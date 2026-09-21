<?php

namespace App\Http\Controllers;

use App\Events\WebhookRejected;
use App\Services\IdempotencyService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaystackWebhookController extends Controller
{
    public function handle(
        Request $request,
        PaymentService $paymentService,
        IdempotencyService $idempotency,
    ): JsonResponse {
        /** @var array<string, mixed> $payload */
        $payload = $request->all();
        $signature = $request->header('x-paystack-signature');

        if (! $signature) {
            event(new WebhookRejected('paystack', 'missing_signature'));

            return response()->json(['message' => 'Missing signature.'], 400);
        }

        $secretKey = config('services.paystack.secret_key');
        $expectedSignature = hash_hmac('sha512', $request->getContent(), is_string($secretKey) ? $secretKey : '');

        if (! hash_equals($expectedSignature, $signature)) {
            event(new WebhookRejected('paystack', 'invalid_signature'));

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        /** @var array<string, mixed> $data */
        $data = (array) ($payload['data'] ?? []);
        $event = is_string($payload['event'] ?? null) ? $payload['event'] : 'unknown';
        $reference = is_string($data['reference'] ?? null) ? $data['reference'] : 'no-reference';

        // Paystack retries deliveries; the (event, reference) pair makes
        // redelivery a replay instead of a second posting.
        $result = $idempotency->run(
            scope: 'paystack.webhook',
            key: $event.':'.$reference,
            work: function () use ($paymentService, $payload) {
                $paymentService->handleWebhook($payload);

                return ['processed' => true];
            },
            requestHash: ['event' => $event, 'reference' => $reference],
        );

        return response()->json([
            'message' => $result['replayed'] ? 'Webhook already processed.' : 'Webhook processed.',
            'replayed' => $result['replayed'],
        ])->header('Idempotent-Replayed', $result['replayed'] ? 'true' : 'false');
    }
}
