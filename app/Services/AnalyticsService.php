<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\DailyLedger;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AnalyticsService
{
    private ?Branch $branch = null;

    public function forBranch(Branch $branch): self
    {
        $this->branch = $branch;

        return $this;
    }

    public function getOccupancyPercentage(?RoomType $roomType = null, Carbon|string|null $date = null): float
    {
        $branch = $this->requireBranch();

        $occupancyService = new OccupancyService;

        return $occupancyService->getPercentage($branch->id, $roomType, $date);
    }

    public function getAverageDailyRate(Carbon|string|null $date = null): int
    {
        $branch = $this->requireBranch();
        $date = $date instanceof Carbon ? $date : Carbon::parse($date ?? now());

        $ledger = DailyLedger::forBranch($branch->id)
            ->forDate($date->toDateString())
            ->completed()
            ->first();

        if (! $ledger) {
            return 0;
        }

        $roomsSold = $ledger->rooms_posted;

        if ($roomsSold === 0) {
            return 0;
        }

        return (int) round($ledger->total_room_revenue / $roomsSold);
    }

    public function getRevPAR(Carbon|string|null $date = null): int
    {
        $branch = $this->requireBranch();
        $date = $date instanceof Carbon ? $date : Carbon::parse($date ?? now());

        $totalRooms = Room::forBranch($branch->id)
            ->where('is_active', true)
            ->where('status', '!=', 'out_of_order')
            ->count();

        if ($totalRooms === 0) {
            return 0;
        }

        $ledger = DailyLedger::forBranch($branch->id)
            ->forDate($date->toDateString())
            ->completed()
            ->first();

        if (! $ledger) {
            return 0;
        }

        return (int) round($ledger->total_room_revenue / $totalRooms);
    }

    /**
     * @return array{period_days: int, total_room_revenue: int, total_tax: int, total_other_charges: int, total_payments: int, net_revenue: int, daily: array<int, array{date: string, room_revenue: int, tax: int, other_charges: int, payments: int, net_revenue: int}>}
     */
    public function getRevenueSummary(int $days = 30, ?string $startDate = null, ?string $endDate = null): array
    {
        $branch = $this->requireBranch();
        $start = $startDate ? Carbon::parse($startDate)->startOfDay() : now()->subDays($days)->startOfDay();
        $end = $endDate ? Carbon::parse($endDate)->endOfDay() : now()->endOfDay();

        $ledgers = DailyLedger::forBranch($branch->id)
            ->completed()
            ->where('business_date', '>=', $start->toDateString())
            ->where('business_date', '<=', $end->toDateString())
            ->orderBy('business_date')
            ->get();

        /** @var int $totalRoomRevenue */
        $totalRoomRevenue = $ledgers->reduce(fn (int $carry, DailyLedger $l) => $carry + $l->total_room_revenue, 0);
        /** @var int $totalTax */
        $totalTax = $ledgers->reduce(fn (int $carry, DailyLedger $l) => $carry + $l->total_tax, 0);
        /** @var int $totalOtherCharges */
        $totalOtherCharges = $ledgers->reduce(fn (int $carry, DailyLedger $l) => $carry + $l->total_other_charges, 0);
        /** @var int $totalPayments */
        $totalPayments = $ledgers->reduce(fn (int $carry, DailyLedger $l) => $carry + $l->total_payments, 0);
        /** @var int $totalNetRevenue */
        $totalNetRevenue = $ledgers->reduce(fn (int $carry, DailyLedger $l) => $carry + $l->net_revenue, 0);

        return [
            'period_days' => $startDate && $endDate ? (int) $start->diffInDays($end) : $days,
            'total_room_revenue' => $totalRoomRevenue,
            'total_tax' => $totalTax,
            'total_other_charges' => $totalOtherCharges,
            'total_payments' => $totalPayments,
            'net_revenue' => $totalNetRevenue,
            'daily' => array_values($ledgers->map(fn (DailyLedger $l) => [
                'date' => $l->business_date->toDateString(),
                'room_revenue' => $l->total_room_revenue,
                'tax' => $l->total_tax,
                'other_charges' => $l->total_other_charges,
                'payments' => $l->total_payments,
                'net_revenue' => $l->net_revenue,
            ])->all()),
        ];
    }

    /**
     * @return Collection<int, array{date: string, occupancy_pct: float, occupied_rooms: int, total_rooms: int}>
     */
    public function getOccupancyTrend(int $days = 30, ?string $startDate = null, ?string $endDate = null): Collection
    {
        $branch = $this->requireBranch();
        $start = $startDate ? Carbon::parse($startDate)->startOfDay() : now()->subDays($days)->startOfDay();
        $end = $endDate ? Carbon::parse($endDate)->endOfDay() : now()->endOfDay();

        $totalRooms = Room::forBranch($branch->id)
            ->where('is_active', true)
            ->where('status', '!=', 'out_of_order')
            ->count();

        if ($totalRooms === 0) {
            /** @var Collection<int, array{date: string, occupancy_pct: float, occupied_rooms: int, total_rooms: int}> */
            return collect();
        }

        $occupancyByDate = Reservation::forBranch($branch->id)
            ->checkedIn()
            ->where('check_in_date', '>=', $start->toDateString())
            ->select(
                DB::raw('check_in_date as date'),
                DB::raw('count(*) as occupied_count')
            )
            ->groupBy('check_in_date')
            ->pluck('occupied_count', 'date');

        $trend = [];
        $daysBetween = $start->diffInDays($end);

        for ($i = 0; $i <= $daysBetween; $i++) {
            $dateStr = $start->copy()->addDays($i)->toDateString();
            $rawOccupied = $occupancyByDate->get($dateStr, 0);
            $occupied = is_numeric($rawOccupied) ? (int) $rawOccupied : 0;
            $pct = round(($occupied / $totalRooms) * 100, 1);

            $trend[] = [
                'date' => $dateStr,
                'occupancy_pct' => $pct,
                'occupied_rooms' => $occupied,
                'total_rooms' => $totalRooms,
            ];
        }

        return collect($trend);
    }

    /**
     * @return Collection<int, array{room_type_id: int, room_type_name: string, total_rooms: int, total_revenue: int, rooms_sold: int<0, max>, adr: int}>
     */
    public function getRoomTypePerformance(int $days = 30, ?string $startDate = null, ?string $endDate = null): Collection
    {
        $branch = $this->requireBranch();
        $start = $startDate ?? now()->subDays($days)->toDateString();

        $roomTypes = RoomType::forBranch($branch->id)
            ->active()
            ->withCount([
                'rooms as total_rooms' => function ($q) {
                    /** @var HasMany<Room, RoomType> $q */
                    $q->where('is_active', true);
                },
            ])
            ->get();

        $reservationStats = Reservation::forBranch($branch->id)
            ->whereIn('status', ['checked_in', 'checked_out'])
            ->where('check_in_date', '>=', $start)
            ->select('room_type_id', 'total_amount', 'status')
            ->get()
            ->groupBy('room_type_id');

        return $roomTypes->map(function (RoomType $roomType) use ($reservationStats) {
            $reservations = $reservationStats->get($roomType->id, collect());

            $totalRevenue = 0;
            /** @var Reservation $r */
            foreach ($reservations as $r) {
                $totalRevenue += (int) $r->total_amount;
            }
            $roomsSold = $reservations->where('status', '!=', 'cancelled')->count();

            return [
                'room_type_id' => $roomType->id,
                'room_type_name' => $roomType->name,
                'total_rooms' => $roomType->total_rooms ?? 0,
                'total_revenue' => $totalRevenue,
                'rooms_sold' => (int) $roomsSold,
                'adr' => $roomsSold > 0 ? (int) round($totalRevenue / $roomsSold) : 0,
            ];
        });
    }

    /**
     * @return array{date: string, occupancy_pct: float, adr: int, revpar: int, revenue_7d: array{period_days: int, total_room_revenue: int, total_tax: int, total_other_charges: int, total_payments: int, net_revenue: int, daily: array<int, array{date: string, room_revenue: int, tax: int, other_charges: int, payments: int, net_revenue: int}>}, revenue_30d: array{period_days: int, total_room_revenue: int, total_tax: int, total_other_charges: int, total_payments: int, net_revenue: int, daily: array<int, array{date: string, room_revenue: int, tax: int, other_charges: int, payments: int, net_revenue: int}>}, occupancy_trend_30d: array<int, array{date: string, occupancy_pct: float, occupied_rooms: int, total_rooms: int}>, room_type_performance: array<int, array{room_type_id: int, room_type_name: string, total_rooms: int, total_revenue: int, rooms_sold: int<0, max>, adr: int}>}
     */
    public function getKpiSummary(Carbon|string|null $date = null, int $days = 30, ?string $startDate = null, ?string $endDate = null): array
    {
        $branch = $this->requireBranch();
        $date = $date instanceof Carbon ? $date : Carbon::parse($date ?? now());
        $cacheKey = "kpi_summary_{$branch->id}_{$date->toDateString()}_{$days}_{$startDate}_{$endDate}";

        return Cache::remember($cacheKey, now()->addMinutes(15), function () use ($date, $days, $startDate, $endDate) {
            return [
                'date' => $date->toDateString(),
                'occupancy_pct' => $this->getOccupancyPercentage(null, $date),
                'adr' => $this->getAverageDailyRate($date),
                'revpar' => $this->getRevPAR($date),
                'revenue_7d' => $this->getRevenueSummary(min($days, 7), $startDate, $endDate),
                'revenue_30d' => $this->getRevenueSummary($days, $startDate, $endDate),
                'occupancy_trend_30d' => $this->getOccupancyTrend($days, $startDate, $endDate)->all(),
                'room_type_performance' => $this->getRoomTypePerformance($days, $startDate, $endDate)->all(),
            ];
        });
    }

    private function requireBranch(): Branch
    {
        if (! $this->branch) {
            throw new \LogicException('Branch must be set before computing analytics.');
        }

        return $this->branch;
    }
}
