<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Supplier;
use App\Models\SupplierInputVatInvoice;
use App\Models\TaxRuleVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierInputVatService
{
    public function record(
        Branch $branch,
        Supplier $supplier,
        User $actor,
        string $invoiceNumber,
        Carbon|string $invoiceDate,
        Carbon|string $supplyDate,
        string $supplierTaxId,
        int $netAmountMinor,
        int $vatAmountMinor,
        int $taxableUseMinor,
        int $exemptUseMinor,
        bool $validatedEinvoice,
        ?string $einvoiceReference,
        string $evidenceReference,
    ): SupplierInputVatInvoice {
        if ($supplier->branch_id !== $branch->id) {
            throw new AuthorizationException('The supplier must belong to the selected property.');
        }

        if (! $actor->is_global_admin && ! $actor->branches()->whereKey($branch->id)->exists()) {
            throw new AuthorizationException('The invoice recorder must belong to the selected property.');
        }

        if (trim($invoiceNumber) === '' || trim($supplierTaxId) === '' || trim($evidenceReference) === '') {
            throw ValidationException::withMessages(['supplier_invoice_number' => 'Invoice number, supplier tax ID, and evidence reference are required.']);
        }

        if ($netAmountMinor < 0 || $vatAmountMinor < 0 || $taxableUseMinor < 0 || $exemptUseMinor < 0
            || $taxableUseMinor + $exemptUseMinor !== $netAmountMinor
        ) {
            throw ValidationException::withMessages(['net_amount_minor' => 'Amounts must be non-negative and taxable plus exempt use must equal the invoice net amount.']);
        }

        if ($validatedEinvoice && ($einvoiceReference === null || trim($einvoiceReference) === '')) {
            throw ValidationException::withMessages(['einvoice_reference' => 'A validated e-invoice reference is required.']);
        }

        $invoiceDateValue = $invoiceDate instanceof Carbon ? $invoiceDate->toDateString() : Carbon::parse($invoiceDate)->toDateString();
        $supplyDateValue = $supplyDate instanceof Carbon ? $supplyDate->toDateString() : Carbon::parse($supplyDate)->toDateString();

        $requiresValidatedInvoice = TaxRuleVersion::query()
            ->where('tenant_id', $branch->tenant_id)
            ->where(function ($query) use ($branch): void {
                $query->whereNull('branch_id')->orWhere('branch_id', $branch->id);
            })
            ->where('rule_key', 'supplier_invoice.validated_einvoice_required')
            ->effectiveOn($supplyDateValue)
            ->orderByRaw('branch_id IS NULL')
            ->orderByDesc('effective_from')
            ->first();

        if (($requiresValidatedInvoice?->value['required'] ?? false) === true && ! $validatedEinvoice) {
            throw ValidationException::withMessages([
                'validated_einvoice' => 'A validated supplier e-invoice is required for input VAT claims on this supply date.',
            ]);
        }

        return DB::transaction(function () use ($branch, $supplier, $actor, $invoiceNumber, $invoiceDateValue, $supplyDateValue, $supplierTaxId, $netAmountMinor, $vatAmountMinor, $taxableUseMinor, $exemptUseMinor, $validatedEinvoice, $einvoiceReference, $evidenceReference, $requiresValidatedInvoice): SupplierInputVatInvoice {
            Branch::query()->whereKey($branch->id)->where('tenant_id', $branch->tenant_id)->lockForUpdate()->firstOrFail();

            return SupplierInputVatInvoice::query()->create([
                'tenant_id' => $branch->tenant_id,
                'branch_id' => $branch->id,
                'supplier_id' => $supplier->id,
                'supplier_invoice_number' => trim($invoiceNumber),
                'supplier_tax_id' => trim($supplierTaxId),
                'invoice_date' => $invoiceDateValue,
                'supply_date' => $supplyDateValue,
                'currency_code' => $branch->currency_code,
                'net_amount_minor' => $netAmountMinor,
                'vat_amount_minor' => $vatAmountMinor,
                'taxable_use_minor' => $taxableUseMinor,
                'exempt_use_minor' => $exemptUseMinor,
                'validated_einvoice' => $validatedEinvoice,
                'einvoice_reference' => $einvoiceReference,
                'evidence_snapshot' => [
                    'reference' => $evidenceReference,
                    'validated_einvoice_rule_version_id' => $requiresValidatedInvoice?->id,
                ],
                'posted_by' => $actor->id,
                'posted_at' => now(),
            ]);
        });
    }

    public function void(SupplierInputVatInvoice $invoice, User $actor, string $reason): SupplierInputVatInvoice
    {
        if (! $actor->is_global_admin && ! $actor->branches()->whereKey($invoice->branch_id)->exists()) {
            throw new AuthorizationException('The invoice voider must belong to the invoice property.');
        }

        if ($invoice->voided_at !== null) {
            return $invoice;
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['void_reason' => 'A reason is required to void a supplier invoice.']);
        }

        $invoice->update([
            'voided_by' => $actor->id,
            'voided_at' => now(),
            'void_reason' => trim($reason),
        ]);

        return $invoice->refresh();
    }
}
