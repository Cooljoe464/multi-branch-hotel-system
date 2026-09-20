<?php

namespace App\Services;

use App\Models\Branding;
use App\Models\Folio;
use App\Models\PaymentTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PaymentService
{
    private string $secretKey;

    private string $baseUrl;

    public function __construct()
    {
        $secretKey = config('services.paystack.secret_key', '');
        $this->secretKey = is_string($secretKey) ? $secretKey : '';
        $this->baseUrl = 'https://api.paystack.co';
    }

    /**
     * @return array{authorization_url: string, access_code: string, reference: string}
     */
    public function initializePayment(Folio $folio, int $amount, string $email, ?string $callbackUrl = null): array
    {
        $reference = 'HMS-'.Str::upper(Str::random(12));

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->secretKey}",
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl}/transaction/initialize", [
            'email' => $email,
            'amount' => $amount,
            'reference' => $reference,
            'callback_url' => $callbackUrl,
            'metadata' => [
                'folio_id' => $folio->id,
                'branch_id' => $folio->branch_id,
                'folio_number' => $folio->folio_number,
            ],
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Paystack initialization failed: '.$response->body());
        }

        /** @var array{authorization_url: string, access_code: string} $data */
        $data = $response->json('data');

        PaymentTransaction::create([
            'branch_id' => $folio->branch_id,
            'folio_id' => $folio->id,
            'reservation_id' => $folio->reservation_id,
            'paystack_reference' => $reference,
            'paystack_access_code' => $data['access_code'],
            'type' => 'charge',
            'status' => 'pending',
            'amount' => $amount,
            'currency' => $folio->branch->currency_code ?? Branding::instance()->currency_code,
            'customer_email' => $email,
        ]);

        return [
            'authorization_url' => $data['authorization_url'],
            'access_code' => $data['access_code'],
            'reference' => $reference,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyTransaction(string $reference): array
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->secretKey}",
        ])->get("{$this->baseUrl}/transaction/verify/{$reference}");

        if (! $response->successful()) {
            throw new \RuntimeException('Paystack verification failed: '.$response->body());
        }

        /** @var array<string, mixed> $data */
        $data = $response->json('data');

        return $data;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhook(array $payload): void
    {
        $event = is_string($payload['event'] ?? null) ? $payload['event'] : '';
        /** @var array<string, mixed> $data */
        $data = (array) ($payload['data'] ?? []);

        $reference = is_string($data['reference'] ?? null) ? $data['reference'] : null;
        if (! $reference) {
            return;
        }

        $paymentTx = PaymentTransaction::byReference($reference)->first();
        if (! $paymentTx) {
            return;
        }

        match ($event) {
            'charge.success' => $this->handleChargeSuccess($paymentTx, $data),
            'authorization.success' => $this->handleAuthorizationSuccess($paymentTx, $data),
            'charge.failed' => $paymentTx->markFailed(['webhook_payload' => $payload]),
            'refund.created' => $this->handleRefund($paymentTx, $data, $payload),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function capturePreauth(string $authorizationCode, int $amount): array
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->secretKey}",
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl}/transaction/charge_authorization", [
            'authorization_code' => $authorizationCode,
            'amount' => $amount,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Paystack pre-auth capture failed: '.$response->body());
        }

        /** @var array<string, mixed> $data */
        $data = $response->json('data');

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function handleChargeSuccess(PaymentTransaction $paymentTx, array $data): void
    {
        if ($paymentTx->status === 'success') {
            return;
        }

        $metadata = $data['metadata'] ?? [];

        $authorization = $data['authorization'] ?? [];
        $paymentTx->markSuccess([
            'webhook_payload' => ['data' => $data],
            'authorization_code' => is_array($authorization) ? ($authorization['authorization_code'] ?? null) : null,
        ]);

        if ($paymentTx->folio_id) {
            $folioService = new FolioService;
            $folio = Folio::find($paymentTx->folio_id);
            if ($folio) {
                $folioService->postCredit(
                    $folio,
                    'payment',
                    "Paystack payment: {$paymentTx->paystack_reference}",
                    $paymentTx->amount,
                    null,
                    PaymentTransaction::class,
                    $paymentTx->id,
                );
            }
        }

        if ($paymentTx->reservation_id) {
            $reservation = $paymentTx->reservation;
            if ($reservation) {
                $reservation->syncPaymentStatus();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function handleAuthorizationSuccess(PaymentTransaction $paymentTx, array $data): void
    {
        $authorization = $data['authorization'] ?? [];
        $paymentTx->update([
            'authorization_code' => is_array($authorization) ? ($authorization['authorization_code'] ?? null) : null,
            'metadata' => array_merge($paymentTx->metadata ?? [], [
                'authorization' => $data['authorization'] ?? null,
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $payload
     */
    private function handleRefund(PaymentTransaction $paymentTx, array $data, array $payload): void
    {
        $refundAmount = is_numeric($data['amount'] ?? null) ? (int) $data['amount'] : 0;

        PaymentTransaction::create([
            'branch_id' => $paymentTx->branch_id,
            'folio_id' => $paymentTx->folio_id,
            'reservation_id' => $paymentTx->reservation_id,
            'paystack_reference' => 'REF-'.$paymentTx->paystack_reference.'-'.Str::upper(Str::random(4)),
            'type' => 'refund',
            'status' => 'success',
            'amount' => $refundAmount,
            'currency' => $paymentTx->currency,
            'paid_at' => isset($data['created_at']) && is_string($data['created_at']) ? Carbon::parse($data['created_at']) : now(),
            'webhook_payload' => $payload,
        ]);

        if ($paymentTx->folio_id) {
            $folioService = new FolioService;
            $folio = Folio::find($paymentTx->folio_id);
            if ($folio) {
                $folioService->postDebit(
                    $folio,
                    'refund',
                    "Refund: {$paymentTx->paystack_reference}",
                    $refundAmount,
                );
            }
        }

        if ($paymentTx->reservation_id) {
            $reservation = $paymentTx->reservation;
            if ($reservation) {
                $reservation->syncPaymentStatus();
            }
        }
    }
}
