<?php

namespace App\Services\Fiscal;

use App\Contracts\EInvoiceEmitter;
use App\Events\FiscalDocumentHeld;
use App\Jobs\EmitEinvoiceJob;
use App\Models\Branch;
use App\Models\FiscalDocument;
use App\Models\Folio;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns a settled folio into a FIRS e-invoice document and queues its
 * emission. Credentials come from FirsEmitter (branch_secrets first,
 * .env fallback), emission is idempotent per closing version so retries
 * and double checkouts never produce a second IRN, and nothing is
 * queued when the branch has no fiscal credentials configured.
 */
class FiscalService
{
    public function __construct(private EInvoiceEmitter $emitter) {}

    public function isEnabled(Branch $branch): bool
    {
        return $this->emitter->capabilities($branch)['submit'];
    }

    /**
     * Create (once) and queue the e-invoice for a closed folio. Returns
     * null when fiscal emission is unconfigured or the folio is not
     * settled yet — emission is never a checkout blocker.
     */
    public function queueFor(Folio $folio): ?FiscalDocument
    {
        if (! $folio->is_settled) {
            return null;
        }

        $branch = $folio->branch;

        if (! $this->isEnabled($branch)) {
            return null;
        }

        $payload = $this->payloadFor($folio);
        $holdReason = $this->b2bHoldReason($folio, $payload);
        $document = FiscalDocument::firstOrCreate(
            ['idempotency_key' => $this->idempotencyKey($folio)],
            [
                'branch_id' => $folio->branch_id,
                'folio_id' => $folio->id,
                'provider' => $this->emitter->provider(),
                'status' => $holdReason === null ? FiscalDocument::STATUS_READY : FiscalDocument::STATUS_DRAFT,
                'payload' => $payload,
                'last_error' => $holdReason,
            ],
        );

        if ($document->wasRecentlyCreated) {
            if ($holdReason !== null) {
                event(new FiscalDocumentHeld($document));
            } else {
                EmitEinvoiceJob::dispatch($document->id)->afterCommit();
            }
        }

        return $document;
    }

    public function releaseHeldDocument(FiscalDocument $document): FiscalDocument
    {
        if (! $this->emitter->capabilities($document->branch)['submit']) {
            throw new \DomainException('The invoice provider is not configured for this property.');
        }

        return DB::transaction(function () use ($document): FiscalDocument {
            $locked = FiscalDocument::query()->lockForUpdate()->findOrFail($document->id);

            if ($locked->status !== FiscalDocument::STATUS_DRAFT || $locked->document_type !== 'invoice') {
                throw new \DomainException('Only held invoices can be released.');
            }

            $folio = $locked->folio;

            if (! $folio) {
                throw new \DomainException('The held invoice has no folio.');
            }

            $payload = $this->payloadFor($folio);
            $holdReason = $this->b2bHoldReason($folio, $payload);

            if ($holdReason !== null) {
                throw new \DomainException($holdReason);
            }

            $locked->update([
                'payload' => $payload,
                'status' => FiscalDocument::STATUS_READY,
                'last_error' => null,
            ]);

            EmitEinvoiceJob::dispatch($locked->id)->afterCommit();

            return $locked->refresh();
        });
    }

    /**
     * Create one credit-note document tied to an immutable cleared invoice.
     *
     * @param  array<string, mixed>  $payload
     */
    public function issueCreditNote(FiscalDocument $original, array $payload): ?FiscalDocument
    {
        if ($original->status !== FiscalDocument::STATUS_CLEARED || $original->irn === null) {
            throw new \DomainException('Credit notes require a cleared original fiscal invoice.');
        }

        if (! $this->emitter->capabilities($original->branch)['credit_note']) {
            return null;
        }

        $creditNoteNumber = $payload['creditNoteNumber'] ?? null;

        if (! is_string($creditNoteNumber) || trim($creditNoteNumber) === '') {
            throw new \InvalidArgumentException('A credit note number is required.');
        }

        $payload['originalInvoiceReference'] = $original->irn;
        $document = FiscalDocument::firstOrCreate(
            ['idempotency_key' => hash('sha256', 'credit-note:'.$original->id.':'.trim($creditNoteNumber))],
            [
                'branch_id' => $original->branch_id,
                'folio_id' => $original->folio_id,
                'document_type' => 'credit_note',
                'original_document_id' => $original->id,
                'provider' => $this->emitter->provider(),
                'status' => FiscalDocument::STATUS_READY,
                'payload' => $payload,
            ],
        );

        if ($document->wasRecentlyCreated) {
            EmitEinvoiceJob::dispatch($document->id)->afterCommit();
        }

        return $document;
    }

    /**
     * The FIRS invoice body: supplier/buyer parties, one line per live
     * folio charge, and money in integer minor units.
     *
     * @return array<string, mixed>
     */
    public function payloadFor(Folio $folio): array
    {
        $folio->loadMissing(['branch', 'reservation.corporateAccount']);
        $folio->load('transactions');

        $transactions = $this->billableLines($folio);
        $branch = $folio->branch;

        $lines = $transactions->map(fn (Transaction $transaction): array => [
            'description' => $transaction->description,
            'category' => $transaction->category,
            'quantity' => 1,
            'unitPrice' => $transaction->amount,
            'amount' => $transaction->amount,
            'taxable' => (bool) $transaction->is_taxable,
            'taxAmount' => $transaction->tax_amount,
        ])->values()->all();

        $netTotal = 0;
        $taxTotal = 0;

        foreach ($transactions as $transaction) {
            $netTotal += $transaction->amount;
            $taxTotal += $transaction->tax_amount;
        }

        $reservation = $folio->reservation;
        $corporate = $reservation?->corporateAccount;
        $buyerName = $corporate?->name ?? $folio->guest_name ?? $reservation?->guest_name;

        $buyer = [
            'name' => $buyerName ?? 'Walk-in guest',
            'email' => $reservation?->guest_email,
            'type' => $corporate === null ? 'individual' : 'business',
        ];

        if ($corporate !== null) {
            $buyer['address'] = $corporate->billing_address;
            $buyer['taxId'] = $corporate->tax_id;
        }

        return [
            'invoiceNumber' => $folio->folio_number,
            'invoiceDate' => ($folio->closed_at ?? now())->toDateString(),
            'currency' => $folio->currency_code,
            'supplier' => [
                'name' => $branch->name,
                'tin' => $this->supplierTin($branch),
                'address' => $branch->address,
                'city' => $branch->city,
                'country' => $branch->country,
            ],
            'buyer' => $buyer,
            'lines' => $lines,
            'subAmount' => $netTotal,
            'taxAmount' => $taxTotal,
            'totalAmount' => $netTotal + $taxTotal,
        ];
    }

    /**
     * Stable per-closing key: closing the same folio twice (or two
     * concurrent checkouts) collapses into one emission.
     */
    private function idempotencyKey(Folio $folio): string
    {
        return hash('sha256', 'firs:'.$folio->id.':'.$folio->version);
    }

    /**
     * @return Collection<int, Transaction>
     */
    private function billableLines(Folio $folio): Collection
    {
        return $folio->transactions()
            ->where('type', 'debit')
            ->where('is_voided', false)
            ->orderBy('id')
            ->get();
    }

    private function supplierTin(Branch $branch): ?string
    {
        $settings = $branch->settings;
        $fiscal = is_array($settings) ? ($settings['fiscal'] ?? null) : null;

        if (! is_array($fiscal)) {
            return null;
        }

        $tin = $fiscal['tin'] ?? null;

        if (! is_string($tin) || trim($tin) === '') {
            return null;
        }

        return trim($tin);
    }

    /** @param array<string, mixed> $payload */
    private function b2bHoldReason(Folio $folio, array $payload): ?string
    {
        $folio->loadMissing('reservation');

        if ($folio->reservation?->corporate_account_id === null) {
            return null;
        }

        $buyer = $payload['buyer'] ?? null;

        if (! is_array($buyer) || ! is_string($buyer['name'] ?? null) || trim($buyer['name']) === '') {
            return 'B2B fiscal invoice held: buyer name is required.';
        }

        if (! is_string($buyer['address'] ?? null) || trim($buyer['address']) === '') {
            return 'B2B fiscal invoice held: buyer billing address is required.';
        }

        if (! is_string($buyer['taxId'] ?? null) || preg_match('/^[0-9]{13}$/', $buyer['taxId']) !== 1) {
            return 'B2B fiscal invoice held: a valid 13-digit Tax ID is required.';
        }

        return null;
    }
}
