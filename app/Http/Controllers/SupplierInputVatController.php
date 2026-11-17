<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Branch;
use App\Models\Supplier;
use App\Models\SupplierInputVatInvoice;
use App\Models\TaxRuleVersion;
use App\Services\SupplierInputVatService;
use App\Services\TaxRuleVersionService;
use App\Services\VatWorkpaperService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SupplierInputVatController extends Controller
{
    use EnsuresBranchAccess;

    /**
     * Monthly VAT workpaper, supplier input-VAT invoices and the published
     * tax rule versions in one screen. The JSON endpoints below stay for API use.
     */
    public function compliance(Request $request, Branch $branch, VatWorkpaperService $workpapers): Response
    {
        $this->ensureBranchAccess($branch);

        $month = $request->string('month')->value();
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1 ? $month : now()->format('Y-m');

        $workpaper = null;
        $workpaperError = null;

        try {
            $workpaper = $workpapers->forMonth($branch, $month);
        } catch (ValidationException $e) {
            $workpaperError = collect($e->errors())->flatten()->first();
        }

        $ruleVersions = TaxRuleVersion::query()
            ->where('tenant_id', $branch->tenant_id)
            ->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $branch->id))
            ->orderByDesc('effective_from')
            ->limit(50)
            ->get()
            ->map(fn (TaxRuleVersion $rule): array => [
                'id' => $rule->id,
                'rule_key' => $rule->rule_key,
                'value' => $rule->value,
                'effective_from' => $rule->effective_from->toDateString(),
                'effective_until' => $rule->effective_until?->toDateString(),
                'approval_reference' => $rule->approval_reference,
                'scope' => $rule->branch_id === null ? 'all_properties' : 'this_property',
            ])
            ->values();

        return Inertia::render('finance/TaxCompliance', [
            'branch' => $branch->only(['id', 'name', 'currency_code']),
            'month' => $month,
            'workpaper' => $workpaper,
            'workpaperError' => $workpaperError,
            'ruleVersions' => $ruleVersions,
            'suppliers' => Supplier::query()->where('branch_id', $branch->id)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, Branch $branch, SupplierInputVatService $invoices): JsonResponse|RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        $request->validate([
            'supplier_id' => 'required|integer',
            'supplier_invoice_number' => 'required|string|max:128',
            'supplier_tax_id' => 'required|string|max:32',
            'invoice_date' => 'required|date',
            'supply_date' => 'required|date',
            'net_amount_minor' => 'required|integer|min:0',
            'vat_amount_minor' => 'required|integer|min:0',
            'taxable_use_minor' => 'required|integer|min:0',
            'exempt_use_minor' => 'required|integer|min:0',
            'validated_einvoice' => 'required|boolean',
            'einvoice_reference' => 'nullable|required_if:validated_einvoice,true|string|max:128',
            'evidence_reference' => 'required|string|max:255',
        ]);

        $actor = $request->user();
        abort_unless($actor !== null, 401);

        $supplier = Supplier::query()
            ->where('branch_id', $branch->id)
            ->findOrFail($request->integer('supplier_id'));

        $invoice = $invoices->record(
            branch: $branch,
            supplier: $supplier,
            actor: $actor,
            invoiceNumber: $request->string('supplier_invoice_number')->value(),
            invoiceDate: $request->string('invoice_date')->value(),
            supplyDate: $request->string('supply_date')->value(),
            supplierTaxId: $request->string('supplier_tax_id')->value(),
            netAmountMinor: $request->integer('net_amount_minor'),
            vatAmountMinor: $request->integer('vat_amount_minor'),
            taxableUseMinor: $request->integer('taxable_use_minor'),
            exemptUseMinor: $request->integer('exempt_use_minor'),
            validatedEinvoice: $request->boolean('validated_einvoice'),
            einvoiceReference: $request->string('einvoice_reference')->value() ?: null,
            evidenceReference: $request->string('evidence_reference')->value(),
        );

        if ($request->header('X-Inertia')) {
            return back()->with('toast', ['type' => 'success', 'message' => 'Supplier invoice recorded.']);
        }

        return response()->json(['data' => $invoice], 201);
    }

    public function void(Request $request, Branch $branch, SupplierInputVatInvoice $invoice, SupplierInputVatService $invoices): JsonResponse|RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($invoice->branch_id === $branch->id, 404);
        $request->validate(['reason' => 'required|string|max:255']);

        $actor = $request->user();
        abort_unless($actor !== null, 401);

        $voided = $invoices->void($invoice, $actor, $request->string('reason')->value());

        if ($request->header('X-Inertia')) {
            return back()->with('toast', ['type' => 'success', 'message' => 'Supplier invoice voided.']);
        }

        return response()->json(['data' => $voided]);
    }

    public function workpaper(Request $request, Branch $branch, VatWorkpaperService $workpapers): JsonResponse
    {
        $this->ensureBranchAccess($branch);
        $request->validate(['month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);

        return response()->json([
            'data' => $workpapers->forMonth($branch, $request->string('month')->value()),
        ]);
    }

    public function storeRuleVersion(Request $request, Branch $branch, TaxRuleVersionService $rules): JsonResponse|RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        $request->validate([
            'scope_branch_id' => 'nullable|integer|exists:branches,id',
            'rule_key' => 'required|string|max:96',
            'value' => 'required|array',
            'effective_from' => 'required|date',
            'effective_until' => 'nullable|date|after_or_equal:effective_from',
            'evidence_snapshot' => 'required|array|min:1',
            'approval_reference' => 'required|string|max:255',
        ]);

        $actor = $request->user();
        abort_unless($actor !== null, 401);
        $value = $request->input('value');
        $evidenceSnapshot = $request->input('evidence_snapshot');
        abort_unless(is_array($value) && is_array($evidenceSnapshot), 422);

        $rule = $rules->publish(
            tenantId: $branch->tenant_id,
            branchId: $request->has('scope_branch_id') ? $request->integer('scope_branch_id') : null,
            ruleKey: $request->string('rule_key')->value(),
            value: $this->stringKeyed($value),
            effectiveFrom: $request->string('effective_from')->value(),
            effectiveUntil: $request->string('effective_until')->value() ?: null,
            evidenceSnapshot: $this->stringKeyed($evidenceSnapshot),
            approvalReference: $request->string('approval_reference')->value(),
            approver: $actor,
            creator: $actor,
        );

        if ($request->header('X-Inertia')) {
            return back()->with('toast', ['type' => 'success', 'message' => 'Tax rule version published.']);
        }

        return response()->json(['data' => $rule], 201);
    }

    /**
     * @param  array<mixed>  $values
     * @return array<string, mixed>
     */
    private function stringKeyed(array $values): array
    {
        $normalized = [];

        foreach ($values as $key => $value) {
            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }
}
