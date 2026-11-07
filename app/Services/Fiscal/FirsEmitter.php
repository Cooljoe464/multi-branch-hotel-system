<?php

namespace App\Services\Fiscal;

use App\Contracts\EInvoiceEmitter;
use App\Models\Branch;
use App\Models\FiscalDocument;
use App\Services\Security\SecretManager;
use App\Services\Security\VendorRateLimiter;
use DateTimeInterface;
use Illuminate\Support\Facades\Http;

/**
 * Configured fiscal-provider HTTP adapter used behind the EInvoiceProvider contract.
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

    public const SECRET_STATUS_ENDPOINT = 'firs.status_endpoint';

    public const SECRET_CREDIT_NOTE_ENDPOINT = 'firs.credit_note_endpoint';

    public const SECRET_RECONCILE_ENDPOINT = 'firs.reconcile_endpoint';

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
        $result = $this->submit($document, $document->idempotency_key);

        return ['ok' => $result['ok'], 'irn' => $result['irn'], 'error' => $result['error']];
    }

    /**
     * @return array{submit: bool, status: bool, credit_note: bool, reconcile: bool}
     */
    public function capabilities(Branch $branch): array
    {
        $hasCredentials = $this->apiKey($branch) !== null;

        return [
            'submit' => $hasCredentials && $this->endpoint($branch) !== null,
            'status' => $hasCredentials && $this->statusEndpoint($branch) !== null,
            'credit_note' => $hasCredentials && $this->creditNoteEndpoint($branch) !== null,
            'reconcile' => $hasCredentials && $this->reconcileEndpoint($branch) !== null,
        ];
    }

    /**
     * @return array{ok: bool, submitted: bool, state: ?string, reference: ?string, irn: ?string, digital_stamp: ?string, qr_data: ?string, response_hash: ?string, cleared_at: ?string, error: ?string}
     */
    public function submit(FiscalDocument $document, string $idempotencyKey): array
    {
        $branch = $document->branch;

        if (! $this->limiter->attempt('firs', $branch->id, self::RATE_LIMIT, self::RATE_DECAY)) {
            $wait = $this->limiter->availableIn('firs', $branch->id);

            return $this->failedResult("FIRS rate limit reached; retry in {$wait}s.");
        }

        $endpoint = $this->endpoint($branch);
        $apiKey = $this->apiKey($branch);

        if ($endpoint === null || $apiKey === null) {
            return $this->failedResult('FIRS endpoint or API key not configured.');
        }

        $payload = $document->payload ?? [];

        $invalid = $this->validate($payload);

        if ($invalid !== null) {
            return $this->failedResult($invalid);
        }

        $signature = hash_hmac('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR), $apiKey);

        try {
            $response = Http::connectTimeout(5)->timeout(30)->withHeaders([
                ...$this->requestHeaders($apiKey, $signature),
                'Idempotency-Key' => $idempotencyKey,
            ])->post($endpoint, $payload);
        } catch (\Throwable $e) {
            report($e);

            return $this->failedResult('FIRS transport failed: '.$e->getMessage(), submitted: true);
        }

        if (! $response->successful()) {
            return $this->failedResult("FIRS rejected the invoice (HTTP {$response->status()}).", submitted: true);
        }

        return $this->normalizedResult($response->json(), $response->body(), submitted: true);
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
     * @return array{ok: bool, state: ?string, reference: ?string, digital_stamp: ?string, qr_data: ?string, response_hash: ?string, cleared_at: ?string, error: ?string}
     */
    public function status(Branch $branch, string $reference): array
    {
        $endpoint = $this->optionalEndpoint($branch, self::SECRET_STATUS_ENDPOINT, 'status_endpoint');
        $apiKey = $this->apiKey($branch);

        if ($endpoint === null || $apiKey === null) {
            return $this->statusFailure('FIRS status lookup is not configured.');
        }

        if (! $this->allowVendorCall($branch, $error)) {
            return $this->statusFailure($error);
        }

        $payload = ['reference' => $reference];
        $signature = hash_hmac('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR), $apiKey);
        $url = str_contains($endpoint, '{reference}')
            ? str_replace('{reference}', rawurlencode($reference), $endpoint)
            : $endpoint;

        try {
            $request = Http::connectTimeout(5)->timeout(30)->withHeaders($this->requestHeaders($apiKey, $signature));
            $response = str_contains($endpoint, '{reference}')
                ? $request->get($url)
                : $request->get($url, $payload);
        } catch (\Throwable $exception) {
            report($exception);

            return $this->statusFailure('FIRS status lookup transport failed.');
        }

        if (! $response->successful()) {
            return $this->statusFailure("FIRS status lookup failed (HTTP {$response->status()}).");
        }

        $result = $this->normalizedResult($response->json(), $response->body(), submitted: false);

        return [
            'ok' => $result['ok'],
            'state' => $result['state'],
            'reference' => $result['reference'],
            'digital_stamp' => $result['digital_stamp'],
            'qr_data' => $result['qr_data'],
            'response_hash' => $result['response_hash'],
            'cleared_at' => $result['cleared_at'],
            'error' => $result['error'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, submitted: bool, state: ?string, reference: ?string, irn: ?string, digital_stamp: ?string, qr_data: ?string, response_hash: ?string, cleared_at: ?string, error: ?string}
     */
    public function issueCreditNote(FiscalDocument $original, array $payload, string $idempotencyKey): array
    {
        if ($original->status !== FiscalDocument::STATUS_CLEARED || $original->irn === null) {
            return $this->failedResult('A credit note requires a cleared original invoice reference.');
        }

        if ($this->stringOrNull($payload['creditNoteNumber'] ?? null) === null
            || ! is_array($payload['lines'] ?? null)
            || $payload['lines'] === []
            || ! is_int($payload['totalAmount'] ?? null)
            || $payload['totalAmount'] <= 0
        ) {
            return $this->failedResult('Credit note payload is missing its number, lines, or positive total.');
        }

        $branch = $original->branch;
        $endpoint = $this->optionalEndpoint($branch, self::SECRET_CREDIT_NOTE_ENDPOINT, 'credit_note_endpoint');
        $apiKey = $this->apiKey($branch);

        if ($endpoint === null || $apiKey === null) {
            return $this->failedResult('FIRS credit note issuance is not configured.');
        }

        if (! $this->allowVendorCall($branch, $error)) {
            return $this->failedResult($error);
        }

        $payload['originalInvoiceReference'] = $original->irn;
        $signature = hash_hmac('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR), $apiKey);

        try {
            $response = Http::connectTimeout(5)->timeout(30)->withHeaders([
                ...$this->requestHeaders($apiKey, $signature),
                'Idempotency-Key' => $idempotencyKey,
            ])->post($endpoint, $payload);
        } catch (\Throwable $exception) {
            report($exception);

            return $this->failedResult('FIRS credit note transport failed.', submitted: true);
        }

        if (! $response->successful()) {
            return $this->failedResult("FIRS rejected the credit note (HTTP {$response->status()}).", submitted: true);
        }

        return $this->normalizedResult($response->json(), $response->body(), submitted: true);
    }

    /**
     * @return array{ok: bool, documents: list<array<string, mixed>>, error: ?string}
     */
    public function reconcile(Branch $branch, DateTimeInterface $since): array
    {
        $endpoint = $this->optionalEndpoint($branch, self::SECRET_RECONCILE_ENDPOINT, 'reconcile_endpoint');
        $apiKey = $this->apiKey($branch);

        if ($endpoint === null || $apiKey === null) {
            return ['ok' => false, 'documents' => [], 'error' => 'FIRS reconciliation is not configured.'];
        }

        if (! $this->allowVendorCall($branch, $error)) {
            return ['ok' => false, 'documents' => [], 'error' => $error];
        }

        $payload = ['since' => $since->format(DATE_ATOM)];
        $signature = hash_hmac('sha256', (string) json_encode($payload, JSON_THROW_ON_ERROR), $apiKey);

        try {
            $response = Http::connectTimeout(5)->timeout(30)
                ->withHeaders($this->requestHeaders($apiKey, $signature))
                ->get($endpoint, $payload);
        } catch (\Throwable $exception) {
            report($exception);

            return ['ok' => false, 'documents' => [], 'error' => 'FIRS reconciliation transport failed.'];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'documents' => [], 'error' => "FIRS reconciliation failed (HTTP {$response->status()})."];
        }

        $body = $response->json();
        $documents = is_array($body) ? ($body['documents'] ?? $body['data'] ?? null) : null;

        if (! is_array($documents) || ! array_is_list($documents)) {
            return ['ok' => false, 'documents' => [], 'error' => 'FIRS reconciliation response was malformed.'];
        }

        return ['ok' => true, 'documents' => $documents, 'error' => null];
    }

    public function statusEndpoint(Branch $branch): ?string
    {
        return $this->optionalEndpoint($branch, self::SECRET_STATUS_ENDPOINT, 'status_endpoint');
    }

    public function creditNoteEndpoint(Branch $branch): ?string
    {
        return $this->optionalEndpoint($branch, self::SECRET_CREDIT_NOTE_ENDPOINT, 'credit_note_endpoint');
    }

    public function reconcileEndpoint(Branch $branch): ?string
    {
        return $this->optionalEndpoint($branch, self::SECRET_RECONCILE_ENDPOINT, 'reconcile_endpoint');
    }

    /**
     * @return array<string, string>
     */
    private function requestHeaders(string $apiKey, string $signature): array
    {
        return [
            'Authorization' => "Bearer {$apiKey}",
            'X-Signature' => $signature,
            'Content-Type' => 'application/json',
        ];
    }

    private function optionalEndpoint(Branch $branch, string $secretName, string $configName): ?string
    {
        return $this->secrets->get($branch, $secretName)
            ?? $this->stringOrNull(config("services.firs.{$configName}"));
    }

    private function allowVendorCall(Branch $branch, ?string &$error): bool
    {
        if ($this->limiter->attempt('firs', $branch->id, self::RATE_LIMIT, self::RATE_DECAY)) {
            $error = null;

            return true;
        }

        $error = 'FIRS rate limit reached; retry in '.$this->limiter->availableIn('firs', $branch->id).'s.';

        return false;
    }

    /**
     * @return array{ok: bool, submitted: bool, state: ?string, reference: ?string, irn: ?string, digital_stamp: ?string, qr_data: ?string, response_hash: ?string, cleared_at: ?string, error: ?string}
     */
    private function normalizedResult(mixed $body, string $rawResponse, bool $submitted): array
    {
        if (! is_array($body)) {
            return $this->failedResult('FIRS response was not JSON.', submitted: $submitted);
        }

        $data = is_array($body['data'] ?? null) ? $body['data'] : $body;
        $reference = $this->stringOrNull($data['referenceNumber'] ?? $data['reference'] ?? $data['irn'] ?? null);
        $state = $this->stringOrNull($data['state'] ?? $data['status'] ?? null);
        $state = $state === null ? ($reference === null ? null : FiscalDocument::STATUS_SUBMITTED) : strtolower($state);
        $clearedAt = $this->stringOrNull($data['clearedAt'] ?? $data['cleared_at'] ?? null);

        if ($state === FiscalDocument::STATUS_CLEARED && $clearedAt === null) {
            $clearedAt = now()->toIso8601String();
        }

        if ($reference === null) {
            return $this->failedResult('FIRS response carried no invoice reference.', submitted: $submitted);
        }

        return [
            'ok' => in_array($state, ['submitted', 'cleared'], true),
            'submitted' => $submitted,
            'state' => $state,
            'reference' => $reference,
            'irn' => $reference,
            'digital_stamp' => $this->stringOrNull($data['digitalStamp'] ?? $data['digital_stamp'] ?? null),
            'qr_data' => $this->stringOrNull($data['qrData'] ?? $data['qr_data'] ?? null),
            'response_hash' => hash('sha256', $rawResponse),
            'cleared_at' => $clearedAt,
            'error' => in_array($state, ['submitted', 'cleared'], true)
                ? null
                : 'FIRS response returned unsupported state.',
        ];
    }

    /**
     * @return array{ok: bool, submitted: bool, state: ?string, reference: ?string, irn: ?string, digital_stamp: ?string, qr_data: ?string, response_hash: ?string, cleared_at: ?string, error: ?string}
     */
    private function failedResult(string $error, bool $submitted = false): array
    {
        return [
            'ok' => false,
            'submitted' => $submitted,
            'state' => null,
            'reference' => null,
            'irn' => null,
            'digital_stamp' => null,
            'qr_data' => null,
            'response_hash' => null,
            'cleared_at' => null,
            'error' => $error,
        ];
    }

    /**
     * @return array{ok: bool, state: ?string, reference: ?string, digital_stamp: ?string, qr_data: ?string, response_hash: ?string, cleared_at: ?string, error: ?string}
     */
    private function statusFailure(string $error): array
    {
        return [
            'ok' => false,
            'state' => null,
            'reference' => null,
            'digital_stamp' => null,
            'qr_data' => null,
            'response_hash' => null,
            'cleared_at' => null,
            'error' => $error,
        ];
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

        $buyer = $payload['buyer'] ?? null;

        if (is_array($buyer) && ($buyer['type'] ?? null) === 'business') {
            if ($this->stringOrNull($buyer['name'] ?? null) === null) {
                return 'FIRS business buyer is missing a name.';
            }

            if ($this->stringOrNull($buyer['address'] ?? null) === null) {
                return 'FIRS business buyer is missing an address.';
            }

            $taxId = $this->stringOrNull($buyer['taxId'] ?? null);

            if ($taxId === null || preg_match('/^[0-9]{13}$/', $taxId) !== 1) {
                return 'FIRS business buyer requires a valid 13-digit Tax ID.';
            }
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
