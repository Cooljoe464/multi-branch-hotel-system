<?php

namespace App\Jobs;

use App\Contracts\EInvoiceEmitter;
use App\Events\FiscalDocumentFailed;
use App\Events\FiscalDocumentIssued;
use App\Models\FiscalDocument;
use App\Services\Fiscal\FirsEmitter;
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

        if (! $document || $document->status !== FiscalDocument::STATUS_PENDING) {
            return;
        }

        $document->increment('attempts');

        /** @var EInvoiceEmitter $emitter */
        $emitter = match ($document->provider) {
            'firs' => app(FirsEmitter::class),
            default => app(FirsEmitter::class),
        };

        $result = $emitter->emit($document);

        if ($result['ok']) {
            $document->update(['status' => FiscalDocument::STATUS_ISSUED, 'irn' => $result['irn'], 'last_error' => null]);
            event(new FiscalDocumentIssued($document));

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
}
