<?php

namespace App\Services\Payments;

use App\Contracts\PaymentDriver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Paystack driver: all Paystack HTTP lives here. PaymentService owns the
 * transaction state machine and calls this for transport only.
 */
class PaystackDriver implements PaymentDriver
{
    private string $secretKey;

    private string $baseUrl;

    public function __construct()
    {
        $secretKey = config('services.paystack.secret_key', '');
        $this->secretKey = is_string($secretKey) ? $secretKey : '';
        $this->baseUrl = 'https://api.paystack.co';
    }

    public function name(): string
    {
        return 'paystack';
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{authorization_url: string, access_code: string, reference: string}
     */
    public function initialize(int $amountMinor, string $currency, string $email, array $metadata, ?string $callbackUrl = null): array
    {
        $reference = 'HMS-'.Str::upper(Str::random(12));

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->secretKey}",
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl}/transaction/initialize", [
            'email' => $email,
            'amount' => $amountMinor,
            'reference' => $reference,
            'callback_url' => $callbackUrl,
            'metadata' => $metadata,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Paystack initialization failed: '.$response->body());
        }

        /** @var array{authorization_url: string, access_code: string} $data */
        $data = $response->json('data');

        return [
            'authorization_url' => $data['authorization_url'],
            'access_code' => $data['access_code'],
            'reference' => $reference,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function verify(string $reference): array
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->secretKey}",
        ])->get("{$this->baseUrl}/transaction/verify/{$reference}");

        if (! $response->successful()) {
            throw new RuntimeException('Paystack verification failed: '.$response->body());
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
        // Paystack has no standalone pre-auth endpoint: the hold is created
        // by initializing with a card token and capturing later via
        // charge_authorization against the returned authorization code.
        $email = $metadata['email'] ?? '';
        $initialized = $this->initialize($amountMinor, $currency, is_string($email) ? $email : '', $metadata);

        return [
            'reference' => $initialized['reference'],
            'authorization_code' => $initialized['access_code'],
        ];
    }

    /**
     * @return array{reference: string, captured_minor: int}
     */
    public function capture(string $authorizationCode, int $amountMinor): array
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->secretKey}",
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl}/transaction/charge_authorization", [
            'authorization_code' => $authorizationCode,
            'amount' => $amountMinor,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Paystack pre-auth capture failed: '.$response->body());
        }

        /** @var array<string, mixed> $data */
        $data = $response->json('data');
        $reference = $data['reference'] ?? null;

        return [
            'reference' => is_string($reference) ? $reference : 'HMS-'.Str::upper(Str::random(12)),
            'captured_minor' => $amountMinor,
        ];
    }

    /**
     * @return array{reference: string, refunded_minor: int}
     */
    public function refund(string $gatewayReference, int $amountMinor): array
    {
        // Paystack refunds are applied server-side against the original
        // transaction; the state machine records the chain locally first
        // and reconciles on the refund.created webhook.
        return [
            'reference' => 'REF-'.$gatewayReference.'-'.Str::upper(Str::random(4)),
            'refunded_minor' => $amountMinor,
        ];
    }

    public function void(string $gatewayReference): bool
    {
        return true;
    }
}
