<?php

namespace App\Services\Fiscal;

use App\Contracts\EInvoiceEmitter;
use App\Models\FiscalDocument;
use Illuminate\Support\Facades\Http;

/**
 * Nigeria FIRS e-invoicing emitter (Merchant-Buyer model).
 *
 * Builds the signed invoice payload from the folio bill data and posts it
 * to the configured FIRS endpoint. Sandbox by default; checkout never
 * blocks on emission (queued job with backoff).
 */
class FirsEmitter implements EInvoiceEmitter
{
    public function provider(): string
    {
        return 'firs';
    }

    /**
     * @return array{ok: bool, irn: ?string, error: ?string}
     */
    public function emit(FiscalDocument $document): array
    {
        $payload = $document->payload ?? [];

        $endpoint = config('services.firs.endpoint');
        $endpoint = is_string($endpoint) ? $endpoint : '';
        $apiKey = config('services.firs.api_key');
        $apiKey = is_string($apiKey) ? $apiKey : '';

        if ($endpoint === '' || $apiKey === '') {
            return ['ok' => false, 'irn' => null, 'error' => 'FIRS endpoint or API key not configured.'];
        }

        $signature = hash_hmac('sha256', (string) json_encode($payload), $apiKey);

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

        /** @var mixed $body */
        $body = $response->json();

        if (! is_array($body)) {
            return ['ok' => false, 'irn' => null, 'error' => 'FIRS response was not JSON.'];
        }

        $irn = $body['irn'] ?? null;

        if ($irn === null && isset($body['data']) && is_array($body['data'])) {
            $irn = $body['data']['irn'] ?? null;
        }

        if (! is_string($irn) || $irn === '') {
            return ['ok' => false, 'irn' => null, 'error' => 'FIRS response carried no IRN.'];
        }

        return ['ok' => true, 'irn' => $irn, 'error' => null];
    }
}
