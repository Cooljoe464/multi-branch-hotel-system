<?php

namespace App\Http\Controllers;

use App\Events\WebhookRejected;
use App\Models\PaymentConnection;
use App\Models\PaymentTransaction;
use App\Services\IdempotencyService;
use App\Services\PaymentService;
use App\Support\TenantDatabaseContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentConnectionWebhookController extends Controller
{
    public function handle(
        Request $request,
        string $provider,
        int $connectionId,
        TenantDatabaseContext $tenantContext,
        PaymentService $payments,
        IdempotencyService $idempotency,
    ): JsonResponse {
        abort_unless(in_array($provider, PaymentConnection::PROVIDERS, true), 404);

        return DB::transaction(function () use ($request, $provider, $connectionId, $tenantContext, $payments, $idempotency): JsonResponse {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::select('select set_config(?, ?, true)', ['app.payment_connection_id', (string) $connectionId]);
            }

            $connection = PaymentConnection::query()
                ->whereKey($connectionId)
                ->where('provider', $provider)
                ->where('status', 'active')
                ->first();

            abort_unless($connection instanceof PaymentConnection, 404);

            $response = $tenantContext->run(null, $connection->tenant_id, function () use ($request, $provider, $connectionId, $connection, $payments, $idempotency): JsonResponse {
                if (DB::connection()->getDriverName() === 'pgsql') {
                    DB::select('select set_config(?, ?, true)', ['app.payment_connection_id', (string) $connectionId]);
                }

                if (! $this->signatureIsValid($request, $connection)) {
                    event(new WebhookRejected($provider, 'invalid_connection_signature', $connection->branch_id));

                    return response()->json(['message' => 'Invalid signature.'], 401);
                }

                $payload = $request->all();
                $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
                $referenceValue = $provider === 'paystack' ? ($data['reference'] ?? null) : ($data['tx_ref'] ?? null);

                if (! is_string($referenceValue) || $referenceValue === '') {
                    return response()->json(['message' => 'Webhook ignored.', 'replayed' => false]);
                }

                $transaction = PaymentTransaction::query()->where('paystack_reference', $referenceValue)->first();
                if (! $transaction instanceof PaymentTransaction) {
                    return response()->json(['message' => 'Webhook ignored.', 'replayed' => false]);
                }

                if ($transaction->payment_connection_id !== $connection->id) {
                    event(new WebhookRejected($provider, 'connection_mismatch', $transaction->branch_id));

                    return response()->json(['message' => 'Reference does not belong to this payment connection.'], 403);
                }

                $eventName = $provider === 'paystack'
                    ? (is_string($payload['event'] ?? null) ? $payload['event'] : 'unknown')
                    : match ($data['status'] ?? null) {
                        'successful' => 'charge.success',
                        'failed' => 'charge.failed',
                        default => null,
                    };

                if ($eventName === null) {
                    return response()->json(['message' => 'Non-terminal payment event ignored.', 'replayed' => false]);
                }

                if ($eventName === 'charge.success' && ! $payments->verifiedConnectionPayment($connection, $transaction)) {
                    event(new WebhookRejected($provider, 'provider_verification_mismatch', $transaction->branch_id));

                    return response()->json(['message' => 'Provider verification does not match this transaction.'], 422);
                }

                $normalized = $this->normalizePayload($provider, $payload, $data, $eventName, $referenceValue, $transaction);

                $result = $idempotency->run(
                    scope: "payment-connection.{$connection->id}.webhook",
                    key: $eventName.':'.$referenceValue,
                    work: function () use ($payments, $connection, $normalized): array {
                        $payments->forConnection($connection)->handleWebhook($normalized);

                        return ['processed' => true];
                    },
                    branchId: $transaction->branch_id,
                    requestHash: ['event' => $eventName, 'reference' => $referenceValue],
                );

                return response()->json([
                    'message' => $result['replayed'] ? 'Webhook already processed.' : 'Webhook processed.',
                    'replayed' => $result['replayed'],
                ])->header('Idempotent-Replayed', $result['replayed'] ? 'true' : 'false');
            }, actorType: 'payment_webhook');

            if (! $response instanceof JsonResponse) {
                throw new \LogicException('The payment webhook handler returned an invalid response.');
            }

            return $response;
        });
    }

    /**
     * @param  array<mixed>  $payload
     * @param  array<mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizePayload(
        string $provider,
        array $payload,
        array $data,
        string $eventName,
        string $reference,
        PaymentTransaction $transaction,
    ): array {
        if ($provider === 'paystack') {
            $normalized = [];
            foreach ($payload as $key => $value) {
                if (is_string($key)) {
                    $normalized[$key] = $value;
                }
            }

            return $normalized;
        }

        return [
            'event' => $eventName,
            'data' => [
                'reference' => $reference,
                'id' => isset($data['id']) && (is_int($data['id']) || is_string($data['id'])) ? (string) $data['id'] : null,
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
                'metadata' => is_array($data['meta'] ?? null) ? $data['meta'] : [],
            ],
        ];
    }

    private function signatureIsValid(Request $request, PaymentConnection $connection): bool
    {
        $secret = $connection->webhook_secret ?: $connection->secret_key;

        if ($connection->provider === 'paystack') {
            $signature = $request->header('x-paystack-signature');

            return is_string($signature)
                && $signature !== ''
                && hash_equals(hash_hmac('sha512', $request->getContent(), $secret), $signature);
        }

        $signature = $request->header('verif-hash');

        return is_string($signature) && $signature !== '' && hash_equals($secret, $signature);
    }
}
