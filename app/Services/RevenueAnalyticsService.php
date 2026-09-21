<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Budget;
use App\Models\RevenueSnapshot;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Revenue KPIs over the snapshot grain: ADR, RevPAR, TRevPAR, GOPPAR,
 * pickup/pace, OTB vs budget, demand calendar and segment/source
 * splits. Everything stays in integer minor units; formatting is the
 * client's job. Results cache for 15 minutes keyed by the branch's
 * current business date, so closing the audit naturally invalidates.
 */
class RevenueAnalyticsService
{
    /**
     * @return array{from: string, to: string, adr_minor: int, revpar_minor: int, trevpar_minor: int, goppar_minor: int, occupancy_bps: int, rooms_available: int, rooms_sold: int, room_revenue_minor: int, total_revenue_minor: int, gop_expense_minor: int, pace: list<array{stay_date: string, sold_now: int, sold_then: int, pickup: int, revenue_now_minor: int, revenue_then_minor: int}>, budgets: list<array{month: string, room_nights_target: int, room_nights_otb: int, nights_variance: int, revenue_target_minor: int, revenue_otb_minor: int, revenue_variance_minor: int}>, calendar: list<array{stay_date: string, rooms_available: int, rooms_sold: int, occupancy_bps: int, room_revenue_minor: int}>, by_segment: array<string, array{nights: int, revenue_minor: int}>, by_source: array<string, array{nights: int, revenue_minor: int}>}
     */
    public function metrics(Branch $branch, string $from, string $to): array
    {
        $auditDate = (new BusinessDateService)->current($branch)->business_date->toDateString();

        return Cache::remember(
            "revenue.{$branch->id}.{$from}.{$to}.audit.{$auditDate}",
            900,
            fn () => $this->compute($branch, $from, $to),
        );
    }

    /**
     * @return array{from: string, to: string, adr_minor: int, revpar_minor: int, trevpar_minor: int, goppar_minor: int, occupancy_bps: int, rooms_available: int, rooms_sold: int, room_revenue_minor: int, total_revenue_minor: int, gop_expense_minor: int, pace: list<array{stay_date: string, sold_now: int, sold_then: int, pickup: int, revenue_now_minor: int, revenue_then_minor: int}>, budgets: list<array{month: string, room_nights_target: int, room_nights_otb: int, nights_variance: int, revenue_target_minor: int, revenue_otb_minor: int, revenue_variance_minor: int}>, calendar: list<array{stay_date: string, rooms_available: int, rooms_sold: int, occupancy_bps: int, room_revenue_minor: int}>, by_segment: array<string, array{nights: int, revenue_minor: int}>, by_source: array<string, array{nights: int, revenue_minor: int}>}
     */
    private function compute(Branch $branch, string $from, string $to): array
    {
        $rows = $this->latestRows($branch->id, $from, $to, Carbon::today()->toDateString());

        $available = 0;
        $sold = 0;
        $roomRevenue = 0;
        $totalRevenue = 0;
        $gopExpense = 0;
        $bySegment = [];
        $bySource = [];
        $calendar = [];

        foreach ($rows as $row) {
            $available += $row->rooms_available;
            $sold += $row->rooms_sold;
            $roomRevenue += $row->room_revenue_minor;
            $totalRevenue += $row->total_revenue_minor;
            $gopExpense += $row->gop_expense_minor;

            foreach (['by_segment' => &$bySegment, 'by_source' => &$bySource] as $field => &$bucket) {
                $split = $row->{$field};
                if (is_array($split)) {
                    foreach ($split as $key => $leg) {
                        if (! is_array($leg)) {
                            continue;
                        }
                        $nights = $leg['nights'] ?? 0;
                        $legRevenue = $leg['revenue_minor'] ?? 0;
                        $bucket[$key]['nights'] = ($bucket[$key]['nights'] ?? 0) + (is_int($nights) ? $nights : 0);
                        $bucket[$key]['revenue_minor'] = ($bucket[$key]['revenue_minor'] ?? 0) + (is_int($legRevenue) ? $legRevenue : 0);
                    }
                }
            }
            unset($bucket);

            $calendar[] = [
                'stay_date' => $row->stay_date->toDateString(),
                'rooms_available' => $row->rooms_available,
                'rooms_sold' => $row->rooms_sold,
                'occupancy_bps' => $row->rooms_available > 0
                    ? (int) round($row->rooms_sold * 10000 / $row->rooms_available)
                    : 0,
                'room_revenue_minor' => $row->room_revenue_minor,
            ];
        }

        return [
            'from' => $from,
            'to' => $to,
            'adr_minor' => $sold > 0 ? (int) round($roomRevenue / $sold) : 0,
            'revpar_minor' => $available > 0 ? (int) round($roomRevenue / $available) : 0,
            'trevpar_minor' => $available > 0 ? (int) round($totalRevenue / $available) : 0,
            'goppar_minor' => $available > 0 ? (int) round(($totalRevenue - $gopExpense) / $available) : 0,
            'occupancy_bps' => $available > 0 ? (int) round($sold * 10000 / $available) : 0,
            'rooms_available' => $available,
            'rooms_sold' => $sold,
            'room_revenue_minor' => $roomRevenue,
            'total_revenue_minor' => $totalRevenue,
            'gop_expense_minor' => $gopExpense,
            'pace' => $this->pace($branch->id, $from, $to),
            'budgets' => $this->budgets($branch->id, $from, $to),
            'calendar' => $calendar,
            'by_segment' => $bySegment,
            'by_source' => $bySource,
        ];
    }

    /**
     * Latest snapshot per stay date at or before the reference date.
     *
     * @return Collection<int, RevenueSnapshot>
     */
    private function latestRows(int $branchId, string $from, string $to, string $asOf): Collection
    {
        $ids = RevenueSnapshot::forBranch($branchId)
            ->whereBetween('stay_date', [$from, $to])
            ->where('snapshot_date', '<=', $asOf)
            ->selectRaw('MAX(id) as id')
            ->groupBy('stay_date')
            ->pluck('id');

        return RevenueSnapshot::whereIn('id', $ids)->orderBy('stay_date')->get();
    }

    /**
     * Pickup: same stay dates, OTB now vs OTB 7 snapshots ago.
     *
     * @return list<array{stay_date: string, sold_now: int, sold_then: int, pickup: int, revenue_now_minor: int, revenue_then_minor: int}>
     */
    private function pace(int $branchId, string $from, string $to): array
    {
        $today = Carbon::today()->toDateString();
        $then = Carbon::today()->subDays(7)->toDateString();

        $now = $this->latestRows($branchId, $from, $to, $today)->keyBy(fn (RevenueSnapshot $r) => $r->stay_date->toDateString());
        $past = $this->latestRows($branchId, $from, $to, $then)->keyBy(fn (RevenueSnapshot $r) => $r->stay_date->toDateString());

        $pace = [];
        foreach ((new AvailabilityService)->nights($from, Carbon::parse($to)->addDay()->toDateString()) as $date) {
            $current = $now->has($date) ? $now->get($date) : null;
            $previous = $past->has($date) ? $past->get($date) : null;

            $soldNow = $current instanceof RevenueSnapshot ? $current->rooms_sold : 0;
            $soldThen = $previous instanceof RevenueSnapshot ? $previous->rooms_sold : 0;
            $revenueNow = $current instanceof RevenueSnapshot ? $current->room_revenue_minor : 0;
            $revenueThen = $previous instanceof RevenueSnapshot ? $previous->room_revenue_minor : 0;

            $pace[] = [
                'stay_date' => $date,
                'sold_now' => $soldNow,
                'sold_then' => $soldThen,
                'pickup' => $soldNow - $soldThen,
                'revenue_now_minor' => $revenueNow,
                'revenue_then_minor' => $revenueThen,
            ];
        }

        return $pace;
    }

    /**
     * OTB + actuals per budget month vs target (variance sign: positive
     * means ahead of target).
     *
     * @return list<array{month: string, room_nights_target: int, room_nights_otb: int, nights_variance: int, revenue_target_minor: int, revenue_otb_minor: int, revenue_variance_minor: int}>
     */
    private function budgets(int $branchId, string $from, string $to): array
    {
        $rows = $this->latestRows($branchId, $from, $to, Carbon::today()->toDateString());

        $otbNights = [];
        $otbRevenue = [];
        foreach ($rows as $row) {
            $month = $row->stay_date->copy()->startOfMonth()->toDateString();
            $otbNights[$month] = ($otbNights[$month] ?? 0) + $row->rooms_sold;
            $otbRevenue[$month] = ($otbRevenue[$month] ?? 0) + $row->room_revenue_minor;
        }

        $result = [];
        foreach (Budget::forBranch($branchId)->orderBy('month')->get() as $budget) {
            $month = $budget->month->toDateString();
            $nights = $otbNights[$month] ?? 0;
            $revenue = $otbRevenue[$month] ?? 0;

            $result[] = [
                'month' => $month,
                'room_nights_target' => $budget->room_nights_target,
                'room_nights_otb' => $nights,
                'nights_variance' => $nights - $budget->room_nights_target,
                'revenue_target_minor' => $budget->revenue_target_minor,
                'revenue_otb_minor' => $revenue,
                'revenue_variance_minor' => $revenue - $budget->revenue_target_minor,
            ];
        }

        return $result;
    }
}
