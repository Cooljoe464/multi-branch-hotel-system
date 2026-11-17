<?php

namespace App\Services\Payments;

use App\Contracts\PaymentDriver;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Flutterwave driver (v3 API): all Flutterwave HTTP lives here. Flutterwave
 * takes amounts in major units, so the minor-unit amounts used everywhere
 * else are converted at this boundary only.
 */
class FlutterwaveDriver implements PaymentDriver
{
    private string $secretKey;

    private string $baseUrl = 'https://api.flutterwave.com/v3';

    public function __construct(?string $secretKey = null)
    {
        $secretKey ??= config('services.flutterwave.secret_key', '');
        $this->secretKey = is_string($secretKey) ? $secretKey : '';
    }

    public function name(): string
    {
        return 'flutterwave';
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{authorization_url: string, access_code: string, reference: string}
     */
    public function initialize(int $amountMinor, string $currency, string $email, array $metadata, ?string $callbackUrl = null): array
    {
        $reference = 'HMS-'.Str::upper(Str::random(12));

        $response = $this->request()->post("{$this->baseUrl}/payments", [
            'tx_ref' => $reference,
            'amount' => $this->toMajor($amountMinor),
            'currency' => $currency,
            'redirect_url' => $callbackUrl,
            'customer' => ['email' => $email],
            'meta' => $metadata,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Flutterwave initialization failed: '.$response->body());
        }

        $link = $response->json('data.link');

        if (! is_string($link)) {
            throw new RuntimeException('Flutterwave initialization returned no payment link.');
        }

        return [
            'authorization_url' => $link,
            'access_code' => $reference,
            'reference' => $reference,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function verify(string $reference): array
    {
        $response = $this->request()->get("{$this->baseUrl}/transactions/verify_by_reference", [
            'tx_ref' => $reference,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Flutterwave verification failed: '.$response->body());
        }

        /** @var array<string, mixed> $data */
        $data = $response->json('data');

        return $data;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{reference: string, authorization_code: string}
     */
    public function preauthorize(int $amountMinor, string $currency, string $token, array $metadata): array
    {
        $reference = 'HMS-'.Str::upper(Str::random(12));
        $email = $metadata['email'] ?? '';

        $response = $this->request()->post("{$this->baseUrl}/tokenized-charges", [
            'token' => $token,
            'currency' => $currency,
            'amount' => $this->toMajor($amountMinor),
            'email' => is_string($email) ? $email : '',
            'tx_ref' => $reference,
            'preauthorize' => true,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Flutterwave pre-authorization failed: '.$response->body());
        }

        $flwReference = $response->json('data.flw_ref');

        if (! is_string($flwReference)) {
            throw new RuntimeException('Flutterwave pre-authorization returned no flw_ref.');
        }

        return ['reference' => $reference, 'authorization_code' => $flwReference];
    }

    /**
     * @return array{reference: string, captured_minor: int}
     */
    public function capture(string $authorizationCode, int $amountMinor): array
    {
        $response = $this->request()->post("{$this->baseUrl}/charges/{$authorizationCode}/capture", [
            'amount' => $this->toMajor($amountMinor),
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Flutterwave capture failed: '.$response->body());
        }

        $reference = $response->json('data.tx_ref');

        return [
            'reference' => is_string($reference) ? $reference : $authorizationCode,
            'captured_minor' => $amountMinor,
        ];
    }

    /**
     * @return array{reference: string, refunded_minor: int}
     */
    public function refund(string $gatewayReference, int $amountMinor): array
    {
        $verified = $this->verify($gatewayReference);
        $transactionId = $verified['id'] ?? null;

        if (! is_int($transactionId) && ! is_string($transactionId)) {
            throw new RuntimeException('Flutterwave refund failed: transaction id unknown.');
        }

        $response = $this->request()->post("{$this->baseUrl}/transactions/{$transactionId}/refund", [
            'amount' => $this->toMajor($amountMinor),
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Flutterwave refund failed: '.$response->body());
        }

        $refundId = $response->json('data.id');

        return [
            'reference' => 'REF-'.(is_int($refundId) || is_string($refundId) ? $refundId : $gatewayReference),
            'refunded_minor' => $amountMinor,
        ];
    }

    public function void(string $gatewayReference): bool
    {
        $response = $this->request()->post("{$this->baseUrl}/charges/{$gatewayReference}/void");

        return $response->successful();
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->secretKey)->acceptJson()->asJson();
    }

    private function toMajor(int $amountMinor): float
    {
        return $amountMinor / 100;
    }
}
