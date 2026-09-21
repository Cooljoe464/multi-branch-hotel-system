<?php

namespace App\Services;

use App\Exceptions\RestrictionViolation;
use App\Models\Branch;
use App\Models\RatePlan;
use App\Models\RateRestriction;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Rate restriction evaluation (Min/MaxLOS, CTA, CTD, stop-sell,
 * minimum advance). Pure reads over the stay-date range; specific
 * room-type rows win over all-types rows per field.
 */
class RestrictionService
{
    /**
     * @throws RestrictionViolation
     */
    public function evaluate(
        Branch $branch,
        RatePlan $plan,
        RoomType $roomType,
        string $checkIn,
        string $checkOut,
        ?User $overrider = null,
        ?string $overrideReason = null,
    ): void {
        $nights = (new AvailabilityService)->nights($checkIn, $checkOut);
        $los = count($nights);

        $rows = RateRestriction::forBranch($branch->id)
            ->where('rate_plan_id', $plan->id)
            ->where(function ($query) use ($roomType) {
                $query->where('room_type_id', $roomType->id)->orWhereNull('room_type_id');
            })
            ->whereIn('stay_date', $nights)
            ->orderByRaw('room_type_id NULLS LAST')
            ->get()
            ->groupBy(fn (RateRestriction $row) => $row->stay_date->toDateString());

        $violations = [];

        foreach ($nights as $index => $date) {
            /** @var Collection<int, RateRestriction> $day */
            $day = $rows->get($date, collect());
            $merged = $this->merge($day);

            if ($merged['stop_sell']) {
                $violations['STOP_SELL'][] = $date;
            }

            if ($merged['min_los'] !== null && $los < $merged['min_los']) {
                $violations["MIN_LOS_{$merged['min_los']}"][] = $date;
            }

            if ($merged['max_los'] !== null && $los > $merged['max_los']) {
                $violations["MAX_LOS_{$merged['max_los']}"][] = $date;
            }

            if ($index === 0 && $merged['cta']) {
                $violations['CTA'][] = $date;
            }

            if ($index === count($nights) - 1 && $merged['ctd']) {
                $violations['CTD'][] = $date;
            }

            if ($merged['min_advance_hours'] !== null) {
                $hours = Carbon::now($branch->timezone ?? 'Africa/Lagos')->diffInHours(Carbon::parse($checkIn), false);
                if ($hours < $merged['min_advance_hours']) {
                    $violations["MIN_ADVANCE_{$merged['min_advance_hours']}"][] = $date;
                }
            }
        }

        if ($violations === []) {
            return;
        }

        $code = array_key_first($violations);
        $dates = array_values(array_unique(array_merge(...array_values($violations))));

        if ($overrider !== null && trim((string) $overrideReason) !== ''
            && ($overrider->can('rate_restrictions.override') || (bool) ($overrider->is_global_admin ?? false))) {
            return;
        }

        if ($overrider !== null || ($overrideReason !== null && trim($overrideReason) !== '')) {
            throw new RestrictionViolation('RESTRICTION_OVERRIDE_FORBIDDEN', 'Overriding rate restrictions requires the rate_restrictions.override permission.', $dates);
        }

        $messages = [
            'STOP_SELL' => 'Sale is stopped for the requested dates.',
            'CTA' => 'Arrival is closed for the requested date.',
            'CTD' => 'Departure is closed for the requested date.',
        ];

        throw new RestrictionViolation($code, $messages[$code] ?? "Rate restriction violated: {$code}.", $dates);
    }

    /**
     * Merge specific + generic rows: close flags (CTA/CTD/stop-sell) are
     * additive — any row closes; scalar floors (LOS, advance) resolve
     * specific-wins.
     *
     * @param  Collection<int, RateRestriction>  $day
     * @return array{min_los: int|null, max_los: int|null, cta: bool, ctd: bool, stop_sell: bool, min_advance_hours: int|null}
     */
    private function merge($day): array
    {
        $merged = [
            'min_los' => null,
            'max_los' => null,
            'cta' => false,
            'ctd' => false,
            'stop_sell' => false,
            'min_advance_hours' => null,
        ];

        // Generic first, specific last so specific wins.
        foreach ($day->sortBy(fn (RateRestriction $row) => $row->room_type_id === null ? 0 : 1) as $row) {
            $merged['min_los'] = $row->min_los ?? $merged['min_los'];
            $merged['max_los'] = $row->max_los ?? $merged['max_los'];
            $merged['cta'] = $merged['cta'] || $row->cta;
            $merged['ctd'] = $merged['ctd'] || $row->ctd;
            $merged['stop_sell'] = $merged['stop_sell'] || $row->stop_sell;
            $merged['min_advance_hours'] = $row->min_advance_hours ?? $merged['min_advance_hours'];
        }

        return $merged;
    }
}
