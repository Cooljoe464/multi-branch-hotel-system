<?php

namespace App\Services\Fiscal;

use App\Contracts\EInvoiceEmitter;
use App\Models\Branch;
use App\Models\FiscalDocument;
use App\Services\Security\SecretManager;
use App\Services\Security\VendorRateLimiter;
use Illuminate\Support\Facades\Http;

/**
 * Nigeria FIRS e-invoicing emitter (Merchant-Buyer model).
 *
 * Signs the validated invoice payload and posts it to the configured
 * endpoint. Credentials resolve branch-scoped first (branch_secrets)
 * and fall back to .env; the per-branch RateLimiter bucket keeps one
 * property from exhausting the vendor quota. Checkout never blocks on
 * emission — failures return a result so the queued job retries.
 */
class FirsEmitter implements EInvoiceEmitter
{
    public const SECRET_API_KEY = 'firs.api_key';

    public const SECRET_ENDPOINT = 'firs.endpoint';

    /** Vendor calls allowed per branch per minute. */
    public const RATE_LIMIT = 60;

    public const RATE_DECAY = 60;

    public function __construct(
        private SecretManager $secrets,
        private VendorRateLimiter $limiter,
    ) {}

    public function provider(): string
    {
        return 'firs';
    }

    /**
     * @return array{ok: bool, irn: ?string, error: ?string}
     */
    public function emit(FiscalDocument $document): array
    {
        $branch = $document->branch;

        if (! $this->limiter->attempt('firs', $branch->id, self::RATE_LIMIT, self::RATE_DECAY)) {
            $wait = $this->limiter->availableIn('firs', $branch->id);

            return ['ok' => false, 'irn' => null, 'error' => "FIRS rate limit reached; retry in {$wait}s."];
        }

        $endpoint = $this->endpoint($branch);
        $apiKey = $this->apiKey($branch);

        if ($endpoint === null || $apiKey === null) {
            return ['ok' => false, 'irn' => null, 'error' => 'FIRS endpoint or API key not configured.'];
        }

        $payload = $document->payload ?? [];

        $invalid = $this->validate($payload);

        if ($invalid !== null) {
            return ['ok' => false, 'irn' => null, 'error' => $invalid];
        }

        $signature = hash_hmac('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR), $apiKey);

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'X-Signature' => $signature,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post($endpoint, $payload);
        } catch (\Throwable $e) {
            report($e);

            return ['ok' => false, 'irn' => null, 'error' => 'FIRS transport failed: '.$e->getMessage()];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'irn' => null, 'error' => "FIRS rejected the invoice (HTTP {$response->status()})."];
        }

        $body = $response->json();

        if (! is_array($body)) {
            return ['ok' => false, 'irn' => null, 'error' => 'FIRS response was not JSON.'];
        }

        $irn = $this->stringOrNull($body['irn'] ?? null);

        if ($irn === null && isset($body['data']) && is_array($body['data'])) {
            $irn = $this->stringOrNull($body['data']['irn'] ?? null);
        }

        if ($irn === null) {
            return ['ok' => false, 'irn' => null, 'error' => 'FIRS response carried no IRN.'];
        }

        return ['ok' => true, 'irn' => $irn, 'error' => null];
    }

    public function apiKey(Branch $branch): ?string
    {
        return $this->secrets->get($branch, self::SECRET_API_KEY)
            ?? $this->stringOrNull(config('services.firs.api_key'));
    }

    public function endpoint(Branch $branch): ?string
    {
        return $this->secrets->get($branch, self::SECRET_ENDPOINT)
            ?? $this->stringOrNull(config('services.firs.endpoint'));
    }

    /**
     * Hallucination guard: only a payload shaped like a real invoice
     * leaves the building, so a malformed document never reaches the
     * revenue authority.
     *
     * @param  array<string, mixed>  $payload
     */
    private function validate(array $payload): ?string
    {
        if ($payload === []) {
            return 'FIRS payload is empty.';
        }

        if ($this->stringOrNull($payload['invoiceNumber'] ?? null) === null) {
            return 'FIRS payload is missing invoiceNumber.';
        }

        if ($this->stringOrNull($payload['invoiceDate'] ?? null) === null) {
            return 'FIRS payload is missing invoiceDate.';
        }

        $supplier = $payload['supplier'] ?? null;

        if (! is_array($supplier) || $this->stringOrNull($supplier['name'] ?? null) === null) {
            return 'FIRS payload is missing supplier.name.';
        }

        $lines = $payload['lines'] ?? null;

        if (! is_array($lines) || $lines === []) {
            return 'FIRS payload is missing invoice lines.';
        }

        foreach ($lines as $index => $line) {
            if (! is_array($line)) {
                return "FIRS payload line #{$index} is malformed.";
            }

            if ($this->stringOrNull($line['description'] ?? null) === null) {
                return "FIRS payload line #{$index} is missing a description.";
            }

            if (! is_int($line['amount'] ?? null)) {
                return "FIRS payload line #{$index} needs an integer amount in minor units.";
            }
        }

        if (! is_int($payload['totalAmount'] ?? null)) {
            return 'FIRS payload is missing an integer totalAmount.';
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
