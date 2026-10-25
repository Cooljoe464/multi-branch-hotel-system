<?php

namespace App\Jobs;

use App\Events\OcrCompleted;
use App\Models\GuestIdentityDocument;
use App\Services\Security\SecretManager;
use App\Services\Security\VendorRateLimiter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Vendor OCR for one identity scan. Credentials resolve through
 * SecretManager (.env fallback) and the vendor call sits behind a
 * RateLimiter bucket so a bulk import cannot blow the API quota.
 * Logs carry the document id and status only — never numbers, names
 * or images. Vendor outage (or no vendor configured) degrades to
 * manual entry flagged ocr_pending.
 */
class OcrDocumentJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** Vendor calls allowed per minute (account-wide quota). */
    public const RATE_LIMIT = 30;

    public const RATE_DECAY = 60;

    public function __construct(
        public int $documentId,
    ) {
        $this->onQueue('crm');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(SecretManager $secrets, VendorRateLimiter $limiter): void
    {
        $doc = GuestIdentityDocument::find($this->documentId);

        if (! $doc || $doc->ocr_status !== GuestIdentityDocument::STATUS_PENDING) {
            return;
        }

        $endpoint = $this->stringOrNull($secrets->get(null, 'ocr.endpoint'))
            ?? $this->stringOrNull(config('services.ocr.endpoint'));
        $apiKey = $this->stringOrNull($secrets->get(null, 'ocr.api_key'))
            ?? $this->stringOrNull(config('services.ocr.key'));

        if ($endpoint === null || $apiKey === null) {
            $doc->update(['ocr_status' => GuestIdentityDocument::STATUS_MANUAL]);
            Log::info('OCR skipped: no vendor configured.', ['doc_id' => $doc->id]);

            return;
        }

        if (! $limiter->attempt('ocr', null, self::RATE_LIMIT, self::RATE_DECAY)) {
            $this->release($limiter->availableIn('ocr', null));
            Log::info('OCR deferred: vendor rate limit reached.', ['doc_id' => $doc->id]);

            return;
        }

        if (! is_string($doc->scan_path) || $doc->scan_path === '' || ! Storage::disk('r2')->exists($doc->scan_path)) {
            $doc->update(['ocr_status' => GuestIdentityDocument::STATUS_MANUAL]);
            Log::warning('OCR skipped: scan missing from storage.', ['doc_id' => $doc->id]);

            return;
        }

        try {
            $response = Http::withHeaders(['Authorization' => 'Bearer '.$apiKey])
                ->timeout(60)
                ->post($endpoint, [
                    'doc_type' => $doc->doc_type,
                    'scan_path' => $doc->scan_path,
                ]);

            if (! $response->successful()) {
                throw new \RuntimeException('OCR vendor returned '.$response->status().'.');
            }

            $data = $response->json();

            if (! is_array($data)) {
                throw new \RuntimeException('OCR vendor returned an unreadable payload.');
            }

            $doc->update([
                'ocr_result' => $this->scrub($data),
                'ocr_status' => GuestIdentityDocument::STATUS_DONE,
            ]);
            event(new OcrCompleted($doc->fresh() ?? $doc));

            Log::info('OCR completed.', ['doc_id' => $doc->id]);
        } catch (\Throwable $e) {
            $doc->update(['ocr_status' => GuestIdentityDocument::STATUS_MANUAL]);
            Log::warning('OCR failed; flagged for manual entry.', ['doc_id' => $doc->id, 'error' => $e->getMessage()]);

            throw $e;
        }
    }

    /**
     * Vendor payloads are untrusted: keep scalars only, cut anything
     * oversized, and strip control characters so a hostile response
     * can't smuggle markup or PII blobs into the stored extract.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<string, string>
     */
    private function scrub(array $data): array
    {
        $fields = [];

        foreach ($data as $key => $value) {
            if (! is_string($key) || (! is_string($value) && ! is_int($value))) {
                continue;
            }

            $clean = preg_replace('/[\x00-\x1F\x7F]/', '', (string) $value) ?? '';
            $fields[$key] = mb_substr($clean, 0, 512);
        }

        return $fields;
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
