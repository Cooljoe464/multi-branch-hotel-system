<?php

namespace App\Jobs;

use App\Contracts\EInvoiceEmitter;
use App\Events\FiscalDocumentFailed;
use App\Events\FiscalDocumentIssued;
use App\Models\FiscalDocument;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Transmit one fiscal document with backoff. Unique per document so
 * retries and duplicate dispatches collapse into a single emission.
 */
class EmitEinvoiceJob implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function __construct(public int $fiscalDocumentId)
    {
        $this->onQueue('fiscal');
    }

    public function uniqueId(): string
    {
        return 'emit-einvoice:'.$this->fiscalDocumentId;
    }

    public function handle(): void
    {
        $document = FiscalDocument::find($this->fiscalDocumentId);

        if (! $document || ! in_array($document->status, [FiscalDocument::STATUS_READY, FiscalDocument::STATUS_SUBMITTED], true)) {
            return;
        }

        $document->increment('attempts');

        /** @var EInvoiceEmitter $emitter */
        $emitter = app(EInvoiceEmitter::class);
        $result = $this->providerResult($document, $emitter);

        if ($result['submitted'] && $document->status === FiscalDocument::STATUS_READY) {
            $document->update([
                'status' => FiscalDocument::STATUS_SUBMITTED,
                'irn' => $result['reference'],
                'submitted_at' => now(),
                'last_error' => $result['error'],
            ]);
        }

        if ($result['ok'] && $result['state'] === FiscalDocument::STATUS_CLEARED) {
            $document->update([
                'status' => FiscalDocument::STATUS_CLEARED,
                'irn' => $result['reference'],
                'digital_stamp' => $result['digital_stamp'],
                'qr_data' => $result['qr_data'],
                'response_hash' => $result['response_hash'],
                'cleared_at' => $result['cleared_at'] ?? now(),
                'last_error' => null,
            ]);
            event(new FiscalDocumentIssued($document));

            return;
        }

        if ($result['ok'] && $result['state'] === FiscalDocument::STATUS_SUBMITTED) {
            $document->update([
                'irn' => $result['reference'] ?? $document->irn,
                'last_error' => null,
            ]);
            $this->release($this->backoff()[min($document->attempts - 1, count($this->backoff()) - 1)]);

            return;
        }

        $document->update(['last_error' => $result['error']]);

        if ($document->attempts >= $this->tries) {
            $document->update(['status' => FiscalDocument::STATUS_FAILED]);
            event(new FiscalDocumentFailed($document));

            return;
        }

        $this->release($this->backoff()[min($document->attempts - 1, count($this->backoff()) - 1)]);
    }

    /**
     * @return array{ok: bool, submitted: bool, state: ?string, reference: ?string, irn: ?string, digital_stamp: ?string, qr_data: ?string, response_hash: ?string, cleared_at: ?string, error: ?string}
     */
    private function providerResult(FiscalDocument $document, EInvoiceEmitter $emitter): array
    {
        if ($document->status === FiscalDocument::STATUS_SUBMITTED && $document->irn !== null) {
            $status = $emitter->status($document->branch, $document->irn);

            return [
                ...$status,
                'submitted' => false,
                'irn' => $status['reference'],
            ];
        }

        if ($document->document_type === 'credit_note') {
            $original = $document->originalDocument;

            if (! $original) {
                return [
                    'ok' => false,
                    'submitted' => false,
                    'state' => null,
                    'reference' => null,
                    'irn' => null,
                    'digital_stamp' => null,
                    'qr_data' => null,
                    'response_hash' => null,
                    'cleared_at' => null,
                    'error' => 'Credit note has no original invoice.',
                ];
            }

            return $emitter->issueCreditNote($original, $document->payload ?? [], $document->idempotency_key);
        }

        return $emitter->submit($document, $document->idempotency_key);
    }
}
