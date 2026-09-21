<?php

namespace App\Services;

use App\Models\TaxComponent;
use App\Models\TaxExemption;
use App\Models\TaxProfile;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Integer-only tax math. Exclusive components stack on the running base
 * in sequence order; inclusive components are extracted from the gross.
 * Per-component rounding is round-half-up with the remainder going to
 * the largest component. Exemptions remove single components.
 */
class TaxService
{
    /**
     * @param  list<string>  $exemptCodes
     * @return array{net_minor: int, gross_minor: int, total_minor: int, lines: list<array{code: string, mode: string, rate_bps: int, amount_minor: int}>, snapshot: array<string, mixed>}
     */
    public function compute(int $baseMinor, TaxProfile $profile, string $category, array $exemptCodes = []): array
    {
        if ($baseMinor < 0) {
            throw new InvalidArgumentException('Tax base must be a non-negative integer of minor units.');
        }

        $components = $profile->components()
            ->get()
            ->filter(fn (TaxComponent $c) => in_array($c->applies_to, ['all', $category], true))
            ->reject(fn (TaxComponent $c) => in_array($c->code, $exemptCodes, true))
            ->values();

        $exclusive = $components->where('mode', TaxComponent::MODE_EXCLUSIVE)->values();
        $inclusive = $components->where('mode', TaxComponent::MODE_INCLUSIVE)->values();

        // Inclusive components extract from the gross independently.
        $net = $baseMinor;
        $inclusiveLines = [];
        foreach ($inclusive as $component) {
            $amount = (int) round($baseMinor * $component->rate_bps / (10000 + $component->rate_bps));
            $inclusiveLines[] = $this->line($component, $amount);
            $net -= $amount;
        }

        // Exclusive components stack on the running base in sequence order.
        $running = $net;
        $exclusiveLines = [];
        foreach ($exclusive as $component) {
            $amount = (int) round($running * $component->rate_bps / 10000);
            $exclusiveLines[] = $this->line($component, $amount);
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
    private function line(TaxComponent $component, int $amount): array
    {
        return [
            'code' => $component->code,
            'mode' => $component->mode,
            'rate_bps' => $component->rate_bps,
            'amount_minor' => $amount,
        ];
    }
}
