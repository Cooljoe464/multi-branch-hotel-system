<?php

namespace App\Http\Controllers;

use App\Events\WebhookRejected;
use App\Models\PaymentTransaction;
use App\Services\IdempotencyService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FlutterwaveWebhookController extends Controller
{
    public function handle(
        Request $request,
        PaymentService $paymentService,
        IdempotencyService $idempotency,
    ): JsonResponse {
        $signature = $request->header('verif-hash');

        if (! is_string($signature) || $signature === '') {
            event(new WebhookRejected('flutterwave', 'missing_signature'));

            return response()->json(['message' => 'Missing signature.'], 400);
        }

        $secretHash = config('services.flutterwave.webhook_hash');

        if (! is_string($secretHash) || $secretHash === '' || ! hash_equals($secretHash, $signature)) {
            event(new WebhookRejected('flutterwave', 'invalid_signature'));

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->all();
        /** @var array<string, mixed> $data */
        $data = (array) ($payload['data'] ?? []);
        $reference = is_string($data['tx_ref'] ?? null) ? $data['tx_ref'] : null;

        if ($reference === null) {
            return response()->json(['message' => 'Webhook ignored.', 'replayed' => false]);
        }

        // A valid Flutterwave signature only proves the call came from
        // Flutterwave. It must not settle a transaction owned by another
        // gateway connection.
        if (! $paymentService->referenceBelongsToGateway($reference, 'flutterwave')) {
            $transaction = PaymentTransaction::byReference($reference)->first();
            event(new WebhookRejected('flutterwave', 'connection_mismatch', $transaction?->branch_id));

            return response()->json(['message' => 'Reference does not belong to this gateway.'], 403);
        }

        $status = is_string($data['status'] ?? null) ? $data['status'] : '';
        $event = $status === 'successful' ? 'charge.success' : 'charge.failed';

        if ($event === 'charge.success' && ! $this->amountMatches($reference, $data)) {
            $transaction = PaymentTransaction::byReference($reference)->first();
            event(new WebhookRejected('flutterwave', 'amount_mismatch', $transaction?->branch_id));

            return response()->json(['message' => 'Amount or currency does not match the transaction.'], 422);
        }

        $normalized = [
            'event' => $event,
            'data' => [
                'reference' => $reference,
                'id' => isset($data['id']) && (is_int($data['id']) || is_string($data['id'])) ? (string) $data['id'] : null,
                'metadata' => (array) ($data['meta'] ?? []),
            ],
        ];

        $result = $idempotency->run(
            scope: 'flutterwave.webhook',
            key: $event.':'.$reference,
            work: function () use ($paymentService, $normalized) {
                $paymentService->handleWebhook($normalized);

                return ['processed' => true];
            },
            requestHash: ['event' => $event, 'reference' => $reference],
        );

        return response()->json([
            'message' => $result['replayed'] ? 'Webhook already processed.' : 'Webhook processed.',
            'replayed' => $result['replayed'],
        ])->header('Idempotent-Replayed', $result['replayed'] ? 'true' : 'false');
    }

    /**
     * Flutterwave reports major units; the ledger holds minor units.
     *
     * @param  array<string, mixed>  $data
     */
    private function amountMatches(string $reference, array $data): bool
    {
        $transaction = PaymentTransaction::byReference($reference)->first();

        if ($transaction === null) {
            return true;
        }

        $amount = $data['amount'] ?? null;
        $currency = $data['currency'] ?? null;

        return is_numeric($amount)
            && is_string($currency)
            && (int) round((float) $amount * 100) === $transaction->amount
            && strtoupper($currency) === strtoupper($transaction->currency);
    }
}
