<?php

namespace App\Http\Controllers;

use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaystackWebhookController extends Controller
{
    public function handle(Request $request, PaymentService $paymentService): JsonResponse
    {
        /** @var array<string, mixed> $payload */
        $payload = $request->all();
        $signature = $request->header('x-paystack-signature');

        if (! $signature) {
            return response()->json(['message' => 'Missing signature.'], 400);
        }

        $secretKey = config('services.paystack.secret_key');
        $expectedSignature = hash_hmac('sha512', $request->getContent(), is_string($secretKey) ? $secretKey : '');

        if (! hash_equals($expectedSignature, $signature)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        try {
            $paymentService->handleWebhook($payload);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Webhook processing failed.'], 500);
        }

        return response()->json(['message' => 'Webhook processed.']);
    }
}
