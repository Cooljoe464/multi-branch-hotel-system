<?php

namespace App\Jobs;

use App\Events\OcrCompleted;
use App\Models\GuestIdentityDocument;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Vendor OCR for one identity scan. Logs carry the document id and
 * status only — never numbers, names or images. Vendor outage (or no
 * vendor configured) degrades to manual entry flagged ocr_pending.
 */
class OcrDocumentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

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

    public function handle(): void
    {
        $doc = GuestIdentityDocument::find($this->documentId);

        if (! $doc || $doc->ocr_status !== GuestIdentityDocument::STATUS_PENDING) {
            return;
        }

        $endpoint = config('services.ocr.endpoint');
        $apiKey = config('services.ocr.key');

        if (! is_string($endpoint) || $endpoint === '' || ! is_string($apiKey) || $apiKey === '') {
            $doc->update(['ocr_status' => GuestIdentityDocument::STATUS_MANUAL]);
            Log::info('OCR skipped: no vendor configured.', ['doc_id' => $doc->id]);

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

            $fields = [];
            foreach ($data as $key => $value) {
                if (is_string($key) && (is_string($value) || is_int($value))) {
                    $fields[$key] = $value;
                }
            }

            $doc->update(['ocr_result' => $fields, 'ocr_status' => GuestIdentityDocument::STATUS_DONE]);
            event(new OcrCompleted($doc->fresh() ?? $doc));

            Log::info('OCR completed.', ['doc_id' => $doc->id]);
        } catch (\Throwable $e) {
            $doc->update(['ocr_status' => GuestIdentityDocument::STATUS_MANUAL]);
            Log::warning('OCR failed; flagged for manual entry.', ['doc_id' => $doc->id, 'error' => $e->getMessage()]);

            throw $e;
        }
    }
}
