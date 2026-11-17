<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\TaxRuleVersion;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaxRuleVersionService
{
    /**
     * @param  array<string, mixed>  $value
     * @param  array<string, mixed>  $evidenceSnapshot
     */
    public function publish(
        int $tenantId,
        ?int $branchId,
        string $ruleKey,
        array $value,
        Carbon|string $effectiveFrom,
        Carbon|string|null $effectiveUntil,
        array $evidenceSnapshot,
        string $approvalReference,
        User $approver,
        ?User $creator = null,
    ): TaxRuleVersion {
        if (trim($approvalReference) === '' || $evidenceSnapshot === []) {
            throw ValidationException::withMessages([
                'approval_reference' => 'An approval reference and evidence snapshot are required to publish a tax rule.',
            ]);
        }

        if (preg_match('/^tax_component\..+\.rate_bps$/', $ruleKey) === 1) {
            $rateBps = $value['rate_bps'] ?? null;

            if (! is_int($rateBps) || $rateBps < 0 || $rateBps > 100000) {
                throw ValidationException::withMessages([
                    'value.rate_bps' => 'A tax component rate must be an integer from 0 to 100000 basis points.',
                ]);
            }
        }

        if ($ruleKey === 'tenant.vat_status'
            && ! in_array($value['status'] ?? null, ['registered', 'exempt_small_business', 'opted_in'], true)
        ) {
            throw ValidationException::withMessages([
                'value.status' => 'VAT status must be registered, exempt_small_business, or opted_in.',
            ]);
        }

        if ($ruleKey === 'tenant.vat_status' && $branchId !== null) {
            throw ValidationException::withMessages([
                'branch_id' => 'VAT status is tenant-wide and cannot be published for one property.',
            ]);
        }

        if ($ruleKey === 'supplier_invoice.validated_einvoice_required'
            && ! is_bool($value['required'] ?? null)
        ) {
            throw ValidationException::withMessages([
                'value.required' => 'The supplier e-invoice validation rule must be a boolean.',
            ]);
        }

        $from = $effectiveFrom instanceof Carbon ? $effectiveFrom->toDateString() : Carbon::parse($effectiveFrom)->toDateString();
        $until = $effectiveUntil instanceof Carbon ? $effectiveUntil->toDateString() : ($effectiveUntil === null ? null : Carbon::parse($effectiveUntil)->toDateString());

        if ($until !== null && $until < $from) {
            throw ValidationException::withMessages(['effective_until' => 'The rule end date must be on or after its start date.']);
        }

        return DB::transaction(function () use ($tenantId, $branchId, $ruleKey, $value, $from, $until, $evidenceSnapshot, $approvalReference, $approver, $creator): TaxRuleVersion {
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();

            if (! $approver->is_global_admin && ! $approver->branches()->where('branches.tenant_id', $tenantId)->exists()) {
                throw new AuthorizationException('The tax rule approver must belong to the tenant.');
            }

            if ($branchId !== null) {
                Branch::query()->whereKey($branchId)->where('tenant_id', $tenantId)->firstOrFail();
            }

            return TaxRuleVersion::query()->create([
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'rule_key' => $ruleKey,
                'value' => $value,
                'effective_from' => $from,
                'effective_until' => $until,
                'evidence_snapshot' => $evidenceSnapshot,
                'approval_reference' => $approvalReference,
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'created_by' => $creator?->id,
            ]);
        });
    }
}
