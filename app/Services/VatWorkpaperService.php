<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\SupplierInputVatInvoice;
use App\Models\TaxRuleVersion;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class VatWorkpaperService
{
    /**
     * @return array{
     *     branch_id: int,
     *     period: string,
     *     currency_code: string,
     *     vat_status: string,
     *     output_vat_minor: int,
     *     input_vat_minor: int,
     *     recoverable_input_vat_minor: int,
     *     non_recoverable_input_vat_minor: int,
     *     net_vat_payable_minor: int,
     *     output_transaction_count: int,
     *     supplier_invoice_count: int,
     *     supplier_invoice_ids: list<int>,
     *     output_transactions: list<array{transaction_id: int, business_date: string, vat_minor: int}>,
     *     supplier_invoices: list<array{id: int, supplier_id: int, invoice_number: string, supplier_tax_id: string|null, invoice_date: string, supply_date: string, net_amount_minor: int, input_vat_minor: int, recoverable_input_vat_minor: int, non_recoverable_input_vat_minor: int, ratio_bps: int, validated_einvoice: bool, einvoice_reference: string|null, claimable: bool}>
     * }
     */
    public function forMonth(Branch $branch, string $month): array
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw ValidationException::withMessages(['month' => 'The reporting month must use YYYY-MM format.']);
        }

        $start = Carbon::createFromFormat('!Y-m', $month);

        if (! $start instanceof Carbon) {
            throw ValidationException::withMessages(['month' => 'The reporting month is invalid.']);
        }

        $start->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $vatStatusVersion = TaxRuleVersion::query()
            ->where('tenant_id', $branch->tenant_id)
            ->whereNull('branch_id')
            ->where('rule_key', 'tenant.vat_status')
            ->effectiveOn($end)
            ->latest('effective_from')
            ->first();
        $vatStatus = $vatStatusVersion?->value['status'] ?? 'registered';
        $vatStatus = is_string($vatStatus) ? $vatStatus : 'registered';

        if ($vatStatus === 'exempt_small_business') {
            throw ValidationException::withMessages([
                'month' => 'VAT workpapers are not produced while the tenant is exempt from VAT.',
            ]);
        }

        $transactions = Transaction::query()
            ->whereHas('folio', fn ($query) => $query->where('branch_id', $branch->id))
            ->whereBetween('business_date', [$start->toDateString(), $end->toDateString()])
            ->where('is_voided', false)
            ->get(['id', 'type', 'business_date', 'is_taxable', 'tax_amount', 'tax_snapshot']);

        $outputVat = 0;
        $outputTransactions = [];
        foreach ($transactions as $transaction) {
            if ($transaction->business_date === null) {
                continue;
            }

            $vatMinor = $this->outputVatFor($transaction);
            $outputVat += $vatMinor;
            $outputTransactions[] = [
                'transaction_id' => $transaction->id,
                'business_date' => $transaction->business_date->toDateString(),
                'vat_minor' => $vatMinor,
            ];
        }

        $invoices = SupplierInputVatInvoice::query()
            ->forBranch($branch->id)
            ->whereBetween('supply_date', [$start->toDateString(), $end->toDateString()])
            ->whereNull('voided_at')
            ->whereDate('supply_date', '>=', '2026-01-01')
            ->whereDate('supply_date', '<=', now()->toDateString())
            ->get();

        $inputVat = 0;
        $recoverableInputVat = 0;
        $invoiceIds = [];
        $supplierInvoices = [];

        foreach ($invoices as $invoice) {
            $inputVat += $invoice->vat_amount_minor;
            $claimDeadline = $invoice->supply_date->copy()->addYears(5);
            $apportionment = app(TaxService::class)->apportionInputVat(
                $invoice->vat_amount_minor,
                $invoice->taxable_use_minor,
                $invoice->exempt_use_minor,
            );
            $claimable = ! now()->greaterThan($claimDeadline);
            $recoverableMinor = $claimable ? $apportionment['recoverable_minor'] : 0;
            $recoverableInputVat += $recoverableMinor;

            if ($claimable) {
                $invoiceIds[] = $invoice->id;
            }

            $supplierInvoices[] = [
                'id' => $invoice->id,
                'supplier_id' => $invoice->supplier_id,
                'invoice_number' => $invoice->supplier_invoice_number,
                'supplier_tax_id' => $invoice->supplier_tax_id,
                'invoice_date' => $invoice->invoice_date->toDateString(),
                'supply_date' => $invoice->supply_date->toDateString(),
                'net_amount_minor' => $invoice->net_amount_minor,
                'input_vat_minor' => $invoice->vat_amount_minor,
                'recoverable_input_vat_minor' => $recoverableMinor,
                'non_recoverable_input_vat_minor' => $invoice->vat_amount_minor - $recoverableMinor,
                'ratio_bps' => $apportionment['ratio_bps'],
                'validated_einvoice' => $invoice->validated_einvoice,
                'einvoice_reference' => $invoice->einvoice_reference,
                'claimable' => $claimable,
            ];
        }

        return [
            'branch_id' => $branch->id,
            'period' => $month,
            'currency_code' => $branch->currency_code,
            'vat_status' => $vatStatus,
            'output_vat_minor' => $outputVat,
            'input_vat_minor' => $inputVat,
            'recoverable_input_vat_minor' => $recoverableInputVat,
            'non_recoverable_input_vat_minor' => $inputVat - $recoverableInputVat,
            'net_vat_payable_minor' => $outputVat - $recoverableInputVat,
            'output_transaction_count' => $transactions->count(),
            'supplier_invoice_count' => count($invoiceIds),
            'supplier_invoice_ids' => $invoiceIds,
            'output_transactions' => $outputTransactions,
            'supplier_invoices' => $supplierInvoices,
        ];
    }

    private function outputVatFor(Transaction $transaction): int
    {
        $amount = 0;
        $snapshot = $transaction->tax_snapshot;
        $snapshotLines = is_array($snapshot) ? ($snapshot['lines'] ?? null) : null;

        if (is_array($snapshotLines)) {
            foreach ($snapshotLines as $line) {
                if (is_array($line)
                    && is_string($line['code'] ?? null)
                    && strtoupper($line['code']) === 'VAT'
                    && is_int($line['amount_minor'] ?? null)
                ) {
                    $amount += $line['amount_minor'];
                }
            }
        } elseif ($transaction->is_taxable) {
            $amount = $transaction->tax_amount;
        }

        return $transaction->type === 'credit' ? -$amount : $amount;
    }
}
