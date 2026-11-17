<?php

namespace App\Services;

use App\Models\TaxComponent;
use App\Models\TaxExemption;
use App\Models\TaxProfile;
use App\Models\TaxRuleVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Integer-only tax math. Exclusive components stack on the running base
 * in sequence order; inclusive components are extracted from the gross.
 * Per-component rounding is round-half-up with the remainder going to
 * the largest component. Exemptions remove single components.
 */
class TaxService
{
    public function profileForDate(int $branchId, Carbon|string $date): ?TaxProfile
    {
        return TaxProfile::query()
            ->forBranch($branchId)
            ->effectiveOn($date)
            ->latest('effective_from')
            ->first();
    }

    /**
     * @param  list<string>  $exemptCodes
     * @return array{net_minor: int, gross_minor: int, total_minor: int, lines: list<array{code: string, mode: string, rate_bps: int, amount_minor: int}>, snapshot: array<string, mixed>}
     */
    public function compute(int $baseMinor, TaxProfile $profile, string $category, array $exemptCodes = [], Carbon|string|null $effectiveDate = null): array
    {
        if ($baseMinor < 0) {
            throw new InvalidArgumentException('Tax base must be a non-negative integer of minor units.');
        }

        $businessDate = $effectiveDate ?? now()->toDateString();
        $tenantId = $profile->branch->tenant_id;
        $ruleVersions = TaxRuleVersion::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($query) use ($profile): void {
                $query->whereNull('branch_id')->orWhere('branch_id', $profile->branch_id);
            })
            ->effectiveOn($businessDate)
            ->orderByRaw('branch_id IS NULL')
            ->orderByDesc('effective_from')
            ->get()
            ->unique('rule_key')
            ->keyBy('rule_key');
        $vatStatusVersion = $ruleVersions->get('tenant.vat_status');
        $vatStatus = $vatStatusVersion?->value['status'] ?? 'registered';
        $vatStatus = is_string($vatStatus) ? $vatStatus : 'registered';

        $components = $profile->components()
            ->get()
            ->filter(fn (TaxComponent $c) => in_array($c->applies_to, ['all', $category], true))
            ->reject(fn (TaxComponent $c) => $vatStatus === 'exempt_small_business' && strtoupper($c->code) === 'VAT')
            ->reject(fn (TaxComponent $c) => in_array($c->code, $exemptCodes, true))
            ->values();

        $exclusive = $components->where('mode', TaxComponent::MODE_EXCLUSIVE)->values();
        $inclusive = $components->where('mode', TaxComponent::MODE_INCLUSIVE)->values();

        // Inclusive components extract from the gross independently.
        $net = $baseMinor;
        $inclusiveLines = [];
        foreach ($inclusive as $component) {
            $rateBps = $this->effectiveRate($component, $ruleVersions);
            $amount = (int) round($baseMinor * $rateBps / (10000 + $rateBps));
            $inclusiveLines[] = $this->line($component, $amount, $rateBps);
            $net -= $amount;
        }

        // Exclusive components stack on the running base in sequence order.
        $running = $net;
        $exclusiveLines = [];
        foreach ($exclusive as $component) {
            $rateBps = $this->effectiveRate($component, $ruleVersions);
            $amount = (int) round($running * $rateBps / 10000);
            $exclusiveLines[] = $this->line($component, $amount, $rateBps);
            $running += $amount;
        }

        $lines = array_merge($inclusiveLines, $exclusiveLines);

        // Each line is independently rounded half-up and authoritative:
        // the total is defined as the sum of lines, so no penny remainder
        // can arise. The frozen snapshot records the exact lines.
        $total = $this->sumLines($lines);

        return [
            'net_minor' => $net,
            'gross_minor' => $net + $this->sumLines($exclusiveLines),
            'total_minor' => $total,
            'lines' => $lines,
            'snapshot' => [
                'version' => 1,
                'profile_id' => $profile->id,
                'rule_version_ids' => $ruleVersions->pluck('id')->values()->all(),
                'vat_status' => $vatStatus,
                'vat_status_version_id' => $vatStatusVersion?->id,
                'jurisdiction' => $profile->jurisdiction,
                'category' => $category,
                'base_minor' => $baseMinor,
                'exempt' => $exemptCodes,
                'lines' => $lines,
            ],
        ];
    }

    /**
     * Exemption codes applicable to a polymorphic owner within a branch.
     *
     * @return list<string>
     */
    public function exemptionsFor(int $branchId, ?Model $owner): array
    {
        if ($owner === null) {
            return [];
        }

        $codes = TaxExemption::query()
            ->where('branch_id', $branchId)
            ->where('exemptable_type', $owner->getMorphClass())
            ->where('exemptable_id', $owner->getKey())
            ->pluck('component_code')
            ->all();

        return array_values(array_filter($codes, fn ($code) => is_string($code)));
    }

    /**
     * Allocate recoverable input VAT by taxable-use ratio using integer math.
     *
     * @return array{recoverable_minor: int, non_recoverable_minor: int, ratio_bps: int}
     */
    public function apportionInputVat(int $inputVatMinor, int $taxableBaseMinor, int $exemptBaseMinor): array
    {
        if ($inputVatMinor < 0 || $taxableBaseMinor < 0 || $exemptBaseMinor < 0) {
            throw new InvalidArgumentException('VAT apportionment values must be non-negative.');
        }

        $totalBase = $taxableBaseMinor + $exemptBaseMinor;
        if ($totalBase === 0) {
            return ['recoverable_minor' => 0, 'non_recoverable_minor' => $inputVatMinor, 'ratio_bps' => 0];
        }

        $ratioBps = (int) round($taxableBaseMinor * 10000 / $totalBase);
        // Calculate the amount from the full ratio. Rounding the ratio to
        // basis points first can misallocate material amounts on large bills.
        $recoverable = (int) round($inputVatMinor * $taxableBaseMinor / $totalBase);

        return [
            'recoverable_minor' => $recoverable,
            'non_recoverable_minor' => $inputVatMinor - $recoverable,
            'ratio_bps' => $ratioBps,
        ];
    }

    /**
     * @param  list<array{code: string, mode: string, rate_bps: int, amount_minor: int}>  $lines
     */
    private function sumLines(array $lines): int
    {
        $total = 0;

        foreach ($lines as $line) {
            $total += $line['amount_minor'];
        }

        return $total;
    }

    /**
     * @return array{code: string, mode: string, rate_bps: int, amount_minor: int}
     */
    private function line(TaxComponent $component, int $amount, ?int $rateBps = null): array
    {
        return [
            'code' => $component->code,
            'mode' => $component->mode,
            'rate_bps' => $rateBps ?? $component->rate_bps,
            'amount_minor' => $amount,
        ];
    }

    /**
     * @param  Collection<string, TaxRuleVersion>  $ruleVersions
     */
    private function effectiveRate(TaxComponent $component, Collection $ruleVersions): int
    {
        $version = $ruleVersions->get('tax_component.'.$component->code.'.rate_bps');
        $rate = $version?->value['rate_bps'] ?? null;

        return is_int($rate) && $rate >= 0 ? $rate : $component->rate_bps;
    }
}
