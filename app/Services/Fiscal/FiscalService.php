<?php

namespace App\Services\Fiscal;

use App\Jobs\EmitEinvoiceJob;
use App\Models\Branch;
use App\Models\FiscalDocument;
use App\Models\Folio;
use App\Models\Transaction;
use Illuminate\Support\Collection;

/**
 * Turns a settled folio into a FIRS e-invoice document and queues its
 * emission. Credentials come from FirsEmitter (branch_secrets first,
 * .env fallback), emission is idempotent per closing version so retries
 * and double checkouts never produce a second IRN, and nothing is
 * queued when the branch has no fiscal credentials configured.
 */
class FiscalService
{
    public function __construct(private FirsEmitter $emitter) {}

    public function isEnabled(Branch $branch): bool
    {
        return $this->emitter->endpoint($branch) !== null && $this->emitter->apiKey($branch) !== null;
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

        $document = FiscalDocument::firstOrCreate(
            ['idempotency_key' => $this->idempotencyKey($folio)],
            [
                'branch_id' => $folio->branch_id,
                'folio_id' => $folio->id,
                'provider' => 'firs',
                'status' => FiscalDocument::STATUS_PENDING,
                'payload' => $this->payloadFor($folio),
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
        $folio->loadMissing(['branch', 'reservation']);
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
        $buyerName = $folio->guest_name ?? $reservation?->guest_name;

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
            'buyer' => [
                'name' => $buyerName ?? 'Walk-in guest',
                'email' => $reservation?->guest_email,
            ],
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
}
