<?php

namespace App\Services\Forecasting;

use App\Models\Branch;
use App\Models\DailyLedger;
use App\Models\RevenueSnapshot;
use App\Models\Room;
use Illuminate\Support\Carbon;

/**
 * Heuristics v1: pickup-curve projection blended with a seasonal
 * (same-weekday) baseline, both read from revenue snapshots and
 * audited ledgers. Never touches live rates — it only predicts.
 */
class HeuristicForecaster implements Forecaster
{
    public const VERSION = 'heuristic-v1';

    /**
     * @return array{p_demand: float, expected_rooms: int, features: array<string, mixed>, model_version: string}
     */
    public function forecast(Branch $branch, string $stayDate): array
    {
        $capacity = Room::forBranch($branch->id)
            ->where('is_active', true)
            ->where('status', '!=', 'out_of_order')
            ->count();

        if ($capacity <= 0) {
            return ['p_demand' => 0.0, 'expected_rooms' => 0, 'features' => ['capacity' => 0], 'model_version' => self::VERSION];
        }

        $today = Carbon::today()->toDateString();
        $daysOut = max(0, (int) Carbon::parse($today)->diffInDays(Carbon::parse($stayDate), false));

        $otbRaw = RevenueSnapshot::forBranch($branch->id)
            ->where('stay_date', $stayDate)
            ->orderByDesc('snapshot_date')
            ->value('rooms_sold');
        $otbNow = is_int($otbRaw) ? $otbRaw : 0;

        $pickupPerDay = $this->pickupSlope($branch->id, $stayDate);
        $projected = $otbNow + $pickupPerDay * $daysOut;

        $seasonal = $this->seasonalAverage($branch->id, $stayDate, $capacity);

        $expected = (int) round(min($capacity, max(0, 0.6 * $projected + 0.4 * $seasonal)));

        return [
            'p_demand' => min(1.0, max(0.0, $expected / $capacity)),
            'expected_rooms' => $expected,
            'features' => [
                'capacity' => $capacity,
                'otb_now' => $otbNow,
                'pickup_per_day' => round($pickupPerDay, 3),
                'days_out' => $daysOut,
                'seasonal_avg' => round($seasonal, 1),
            ],
            'model_version' => self::VERSION,
        ];
    }

    /**
     * Daily pickup pace for a stay date from the last week of
     * snapshots (newest minus oldest over elapsed snapshot days).
     */
    private function pickupSlope(int $branchId, string $stayDate): float
    {
        $points = RevenueSnapshot::forBranch($branchId)
            ->where('stay_date', $stayDate)
            ->orderByDesc('snapshot_date')
            ->limit(8)
            ->get(['snapshot_date', 'rooms_sold'])
            ->reverse()
            ->values();

        if ($points->count() < 2) {
            return 0.0;
        }

        $first = $points->first();
        $last = $points->last();

        if (! $first || ! $last) {
            return 0.0;
        }

        $days = $first->snapshot_date->diffInDays($last->snapshot_date);

        if ($days <= 0) {
            return 0.0;
        }

        return max(0.0, ($last->rooms_sold - $first->rooms_sold) / $days);
    }

    /**
     * Same-weekday audited occupancy over the past four weeks,
     * falling back to snapshot actuals when ledgers are missing.
     */
    private function seasonalAverage(int $branchId, string $stayDate, int $capacity): float
    {
        $weekday = Carbon::parse($stayDate)->dayOfWeek;
        $samples = [];

        for ($w = 1; $w <= 4; $w++) {
            $day = Carbon::parse($stayDate)->subWeeks($w)->toDateString();

            if (Carbon::parse($day)->dayOfWeek !== $weekday) {
                continue;
            }

            $ledger = DailyLedger::forBranch($branchId)->forDate($day)->completed()->first();

            if ($ledger) {
                $samples[] = (int) $ledger->rooms_posted;

                continue;
            }

            $snapshot = RevenueSnapshot::forBranch($branchId)
                ->where('stay_date', $day)
                ->orderByDesc('snapshot_date')
                ->first();

            if ($snapshot) {
                $samples[] = (int) $snapshot->rooms_sold;
            }
        }

        if ($samples === []) {
            return 0.0;
        }

        return min((float) $capacity, array_sum($samples) / count($samples));
    }
}
