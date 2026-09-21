<?php

namespace App\Contracts;

use App\Models\FiscalDocument;

/**
 * Pluggable e-invoice emitter (Nigeria FIRS first, others later).
 * Implementations sign and transmit the fiscal payload and record the
 * authority's reference (IRN) on the document. Never throws for
 * transport problems: return a failed result so the job can retry.
 */
interface EInvoiceEmitter
{
    public function provider(): string;

    /**
     * @return array{ok: bool, irn: ?string, error: ?string}
     */
    public function emit(FiscalDocument $document): array;
}
