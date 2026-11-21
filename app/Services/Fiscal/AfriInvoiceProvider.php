<?php

namespace App\Services\Fiscal;

use App\Contracts\EInvoiceEmitter;
use App\Models\Branch;
use App\Models\FiscalDocument;
use App\Services\Security\SecretManager;
use App\Services\Security\VendorRateLimiter;
use DateTimeInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Official Afri Invoice (SI + APP) NRS Accredited E-Invoicing Provider.
 *
 * Handles both:
 * 1. B2C Invoicing: reporting & QR generation for retail/guest folios.
 * 2. B2B Invoicing: pre-clearance with buyer Tax ID (TIN) validation.
 */
class AfriInvoiceProvider implements EInvoiceEmitter
{
    public const SECRET_API_KEY = 'afri_invoice.api_key';

    public const SECRET_SERVICE_ID = 'afri_invoice.service_id';

    public const SECRET_ENVIRONMENT = 'afri_invoice.environment';

    public const SECRET_ENDPOINT = 'afri_invoice.endpoint';

    public const RATE_LIMIT = 120;

    public const RATE_DECAY = 60;

    public const SANDBOX_URL = 'https://sandbox.afrinvoice.com/api/v1';

    public const LIVE_URL = 'https://api.afrinvoice.com/api/v1';

    public function __construct(
        private SecretManager $secrets,
        private VendorRateLimiter $limiter,
    ) {}

    public function provider(): string
    {
        return 'afri_invoice';
    }

    /**
     * @return array{ok: bool, irn: ?string, error: ?string}
     */
    public function emit(FiscalDocument $document): array
    {
        $result = $this->submit($document, $document->idempotency_key);

        return [
            'ok' => $result['ok'],
            'irn' => $result['irn'],
            'error' => $result['error'],
        ];
    }

    /**
     * @return array{submit: bool, status: bool, credit_note: bool, reconcile: bool}
     */
    public function capabilities(Branch $branch): array
    {
        $hasKey = $this->apiKey($branch) !== null;
        $hasServiceId = $this->serviceId($branch) !== null;
        $ready = $hasKey && $hasServiceId;

        return [
            'submit' => $ready,
            'status' => $ready,
            'credit_note' => $ready,
            'reconcile' => $ready,
        ];
    }

    /**
     * @return array{ok: bool, submitted: bool, state: ?string, reference: ?string, irn: ?string, digital_stamp: ?string, qr_data: ?string, response_hash: ?string, cleared_at: ?string, error: ?string}
     */
    public function submit(FiscalDocument $document, string $idempotencyKey): array
    {
        $branch = $document->branch;

        if (! $this->limiter->attempt('afri_invoice', $branch->id, self::RATE_LIMIT, self::RATE_DECAY)) {
            $wait = $this->limiter->availableIn('afri_invoice', $branch->id);

            return $this->failedResult("Afri Invoice rate limit reached; retry in {$wait}s.");
        }

        $apiKey = $this->apiKey($branch);
        $serviceId = $this->serviceId($branch);

        if (! $apiKey || ! $serviceId) {
            return $this->failedResult('Afri Invoice API key or NRS Service ID not configured.');
        }

        $payload = $document->payload ?? [];
        $invalid = $this->validatePayload($payload);
        if ($invalid !== null) {
            return $this->failedResult($invalid);
        }

        $baseUrl = $this->baseUrl($branch);
        $body = $this->transformPayload($payload, $serviceId, $branch);

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'X-Service-ID' => $serviceId,
                'X-Idempotency-Key' => $idempotencyKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->timeout(15)->post("{$baseUrl}/validate-invoice", $body);

            return $this->parseSubmissionResponse($response);
        } catch (\Throwable $e) {
            Log::warning('Afri Invoice submit failed', [
                'branch_id' => $branch->id,
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);

            return $this->failedResult($e->getMessage());
        }
    }

    /**
     * @return array{ok: bool, state: ?string, reference: ?string, digital_stamp: ?string, qr_data: ?string, response_hash: ?string, cleared_at: ?string, error: ?string}
     */
    public function status(Branch $branch, string $reference): array
    {
        $apiKey = $this->apiKey($branch);
        $serviceId = $this->serviceId($branch);

        if (! $apiKey || ! $serviceId) {
            return [
                'ok' => false,
                'state' => null,
                'reference' => $reference,
                'digital_stamp' => null,
                'qr_data' => null,
                'response_hash' => null,
                'cleared_at' => null,
                'error' => 'Afri Invoice credentials not configured.',
            ];
        }

        $baseUrl = $this->baseUrl($branch);

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'X-Service-ID' => $serviceId,
                'Accept' => 'application/json',
            ])->timeout(10)->get("{$baseUrl}/invoices/{$reference}/status");

            if ($response->successful()) {
                $data = $response->json();
                $state = $data['status'] ?? $data['state'] ?? 'PENDING';

                return [
                    'ok' => true,
                    'state' => strtoupper((string) $state),
                    'reference' => $reference,
                    'digital_stamp' => $data['digital_stamp'] ?? $data['signature'] ?? null,
                    'qr_data' => $data['qr_data'] ?? $data['qr_code'] ?? null,
                    'response_hash' => $data['response_hash'] ?? null,
                    'cleared_at' => $data['cleared_at'] ?? null,
                    'error' => null,
                ];
            }

            return [
                'ok' => false,
                'state' => 'ERROR',
                'reference' => $reference,
                'digital_stamp' => null,
                'qr_data' => null,
                'response_hash' => null,
                'cleared_at' => null,
                'error' => $response->json('message') ?? 'Status query failed with HTTP '.$response->status(),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'state' => 'ERROR',
                'reference' => $reference,
                'digital_stamp' => null,
                'qr_data' => null,
                'response_hash' => null,
                'cleared_at' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, submitted: bool, state: ?string, reference: ?string, irn: ?string, digital_stamp: ?string, qr_data: ?string, response_hash: ?string, cleared_at: ?string, error: ?string}
     */
    public function issueCreditNote(FiscalDocument $original, array $payload, string $idempotencyKey): array
    {
        $branch = $original->branch;
        $apiKey = $this->apiKey($branch);
        $serviceId = $this->serviceId($branch);

        if (! $apiKey || ! $serviceId) {
            return $this->failedResult('Afri Invoice credentials not configured.');
        }

        $baseUrl = $this->baseUrl($branch);

        try {
            $body = array_merge($payload, [
                'original_irn' => $original->irn,
                'service_id' => $serviceId,
                'reason' => $payload['reason'] ?? 'Folio adjustment / void',
            ]);

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'X-Service-ID' => $serviceId,
                'X-Idempotency-Key' => $idempotencyKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->timeout(15)->post("{$baseUrl}/credit-note", $body);

            return $this->parseSubmissionResponse($response);
        } catch (\Throwable $e) {
            return $this->failedResult($e->getMessage());
        }
    }

    /**
     * @return array{ok: bool, documents: list<array<string, mixed>>, error: ?string}
     */
    public function reconcile(Branch $branch, DateTimeInterface $since): array
    {
        $apiKey = $this->apiKey($branch);
        $serviceId = $this->serviceId($branch);

        if (! $apiKey || ! $serviceId) {
            return ['ok' => false, 'documents' => [], 'error' => 'Afri Invoice credentials not configured.'];
        }

        $baseUrl = $this->baseUrl($branch);

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'X-Service-ID' => $serviceId,
                'Accept' => 'application/json',
            ])->timeout(20)->get("{$baseUrl}/reconcile", [
                'since' => $since->format(DATE_ATOM),
            ]);

            if ($response->successful()) {
                $docs = $response->json('documents') ?? $response->json('invoices') ?? [];

                return [
                    'ok' => true,
                    'documents' => is_array($docs) ? $docs : [],
                    'error' => null,
                ];
            }

            return ['ok' => false, 'documents' => [], 'error' => 'Reconciliation failed with HTTP '.$response->status()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'documents' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Test connection diagnostic to verify credentials with Afri Invoice.
     *
     * @return array{ok: bool, message: string, merchant_name?: string}
     */
    public function testConnection(?Branch $branch = null, ?string $apiKey = null, ?string $serviceId = null, ?string $env = null): array
    {
        $key = $apiKey ?? ($branch ? $this->apiKey($branch) : null);
        $service = $serviceId ?? ($branch ? $this->serviceId($branch) : null);
        $environment = $env ?? ($branch ? $this->environment($branch) : 'sandbox');

        if (! $key || ! $service) {
            return [
                'ok' => false,
                'message' => 'Both Afri Invoice API Key and NRS Service ID are required.',
            ];
        }

        $url = $environment === 'live' ? self::LIVE_URL : self::SANDBOX_URL;

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$key}",
                'X-Service-ID' => $service,
                'Accept' => 'application/json',
            ])->timeout(8)->get("{$url}/auth/verify");

            if ($response->successful()) {
                $merchant = $response->json('merchant_name') ?? $response->json('organization') ?? 'Verified Hotel Merchant';

                return [
                    'ok' => true,
                    'message' => 'Successfully connected to Afri Invoice (NRS Gateway).',
                    'merchant_name' => (string) $merchant,
                ];
            }

            $errorMsg = $response->json('message') ?? 'Authentication failed with status code '.$response->status();

            return [
                'ok' => false,
                'message' => $errorMsg,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => 'Failed to reach Afri Invoice: '.$e->getMessage(),
            ];
        }
    }

    public function apiKey(?Branch $branch): ?string
    {
        return $this->secrets->get($branch, self::SECRET_API_KEY)
            ?? config('services.afri_invoice.api_key');
    }

    public function serviceId(?Branch $branch): ?string
    {
        return $this->secrets->get($branch, self::SECRET_SERVICE_ID)
            ?? config('services.afri_invoice.service_id');
    }

    public function environment(?Branch $branch): string
    {
        $val = $this->secrets->get($branch, self::SECRET_ENVIRONMENT)
            ?? config('services.afri_invoice.environment', 'sandbox');

        return in_array($val, ['live', 'production'], true) ? 'live' : 'sandbox';
    }

    public function baseUrl(?Branch $branch): string
    {
        $configured = $this->secrets->get($branch, self::SECRET_ENDPOINT);
        if ($configured) {
            return rtrim($configured, '/');
        }

        return $this->environment($branch) === 'live' ? self::LIVE_URL : self::SANDBOX_URL;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validatePayload(array $payload): ?string
    {
        if (empty($payload['invoiceNumber'])) {
            return 'Invoice payload missing invoiceNumber.';
        }

        if (! isset($payload['totalAmount']) || ! is_numeric($payload['totalAmount'])) {
            return 'Invoice payload missing or invalid totalAmount.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function transformPayload(array $payload, string $serviceId, Branch $branch): array
    {
        $isB2b = ! empty($payload['buyerTin']);

        return [
            'service_id' => $serviceId,
            'invoice_number' => $payload['invoiceNumber'] ?? '',
            'issue_date' => $payload['issueDate'] ?? now()->toIso8601String(),
            'transaction_type' => $isB2b ? 'B2B' : 'B2C',
            'buyer' => [
                'tax_id' => $payload['buyerTin'] ?? null,
                'name' => $payload['buyerName'] ?? 'Walk-in Guest',
                'email' => $payload['buyerEmail'] ?? null,
            ],
            'seller' => [
                'branch_id' => $branch->id,
                'name' => $branch->name,
            ],
            'currency' => $payload['currency'] ?? 'NGN',
            'tax_amount' => $payload['taxAmount'] ?? 0,
            'net_amount' => ($payload['totalAmount'] ?? 0) - ($payload['taxAmount'] ?? 0),
            'total_amount' => $payload['totalAmount'] ?? 0,
            'line_items' => $payload['lines'] ?? [],
        ];
    }

    /**
     * @return array{ok: bool, submitted: bool, state: ?string, reference: ?string, irn: ?string, digital_stamp: ?string, qr_data: ?string, response_hash: ?string, cleared_at: ?string, error: ?string}
     */
    private function parseSubmissionResponse(Response $response): array
    {
        if ($response->successful()) {
            $data = $response->json();
            $irn = $data['irn'] ?? $data['invoice_reference_number'] ?? null;
            $qr = $data['qr_code'] ?? $data['qr_data'] ?? null;
            $stamp = $data['digital_stamp'] ?? $data['cryptographic_stamp'] ?? null;
            $status = strtoupper((string) ($data['status'] ?? 'CLEARED'));

            return [
                'ok' => true,
                'submitted' => true,
                'state' => $status,
                'reference' => $data['reference'] ?? $irn,
                'irn' => $irn,
                'digital_stamp' => $stamp,
                'qr_data' => $qr,
                'response_hash' => $data['hash'] ?? $data['response_hash'] ?? null,
                'cleared_at' => $data['cleared_at'] ?? now()->toIso8601String(),
                'error' => null,
            ];
        }

        $errorMsg = $response->json('message')
            ?? $response->json('error')
            ?? 'Afri Invoice validation failed (HTTP '.$response->status().')';

        return $this->failedResult($errorMsg);
    }

    /**
     * @return array{ok: bool, submitted: bool, state: ?string, reference: ?string, irn: ?string, digital_stamp: ?string, qr_data: ?string, response_hash: ?string, cleared_at: ?string, error: string}
     */
    private function failedResult(string $error): array
    {
        return [
            'ok' => false,
            'submitted' => false,
            'state' => 'FAILED',
            'reference' => null,
            'irn' => null,
            'digital_stamp' => null,
            'qr_data' => null,
            'response_hash' => null,
            'cleared_at' => null,
            'error' => $error,
        ];
    }
}
