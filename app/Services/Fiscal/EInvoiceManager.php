<?php

namespace App\Services\Fiscal;

use App\Contracts\EInvoiceEmitter;
use App\Models\Branch;
use App\Models\FiscalDocument;
use DateTimeInterface;

class EInvoiceManager implements EInvoiceEmitter
{
    public function __construct(
        private AfriInvoiceProvider $afriInvoice,
        private FirsEmitter $firs,
    ) {}

    public function resolveForBranch(Branch $branch): EInvoiceEmitter
    {
        $provider = $branch->settings['fiscal_provider'] ?? null;

        if ($provider === 'firs') {
            return $this->firs;
        }

        if ($provider === 'afri_invoice') {
            return $this->afriInvoice;
        }

        // Auto-detect based on configured credentials
        if ($this->afriInvoice->apiKey($branch) !== null) {
            return $this->afriInvoice;
        }

        if ($this->firs->capabilities($branch)['submit']) {
            return $this->firs;
        }

        return $this->afriInvoice;
    }

    public function provider(): string
    {
        return 'manager';
    }

    /**
     * @return array{submit: bool, status: bool, credit_note: bool, reconcile: bool}
     */
    public function capabilities(Branch $branch): array
    {
        return $this->resolveForBranch($branch)->capabilities($branch);
    }

    /**
     * @return array{ok: bool, irn: ?string, error: ?string}
     */
    public function emit(FiscalDocument $document): array
    {
        return $this->resolveForBranch($document->branch)->emit($document);
    }

    /**
     * @return array{ok: bool, submitted: bool, state: ?string, reference: ?string, irn: ?string, digital_stamp: ?string, qr_data: ?string, response_hash: ?string, cleared_at: ?string, error: ?string}
     */
    public function submit(FiscalDocument $document, string $idempotencyKey): array
    {
        return $this->resolveForBranch($document->branch)->submit($document, $idempotencyKey);
    }

    /**
     * @return array{ok: bool, state: ?string, reference: ?string, digital_stamp: ?string, qr_data: ?string, response_hash: ?string, cleared_at: ?string, error: ?string}
     */
    public function status(Branch $branch, string $reference): array
    {
        return $this->resolveForBranch($branch)->status($branch, $reference);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, submitted: bool, state: ?string, reference: ?string, irn: ?string, digital_stamp: ?string, qr_data: ?string, response_hash: ?string, cleared_at: ?string, error: ?string}
     */
    public function issueCreditNote(FiscalDocument $original, array $payload, string $idempotencyKey): array
    {
        return $this->resolveForBranch($original->branch)->issueCreditNote($original, $payload, $idempotencyKey);
    }

    /**
     * @return array{ok: bool, documents: list<array<string, mixed>>, error: ?string}
     */
    public function reconcile(Branch $branch, DateTimeInterface $since): array
    {
        return $this->resolveForBranch($branch)->reconcile($branch, $since);
    }
}
