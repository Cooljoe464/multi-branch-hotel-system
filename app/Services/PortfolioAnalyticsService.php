<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Room;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class PortfolioAnalyticsService
{
    /**
     * Tenant identifiers that opted in to cross-tenant portfolio reporting.
     *
     * @return array<int, int>
     */
    public function consentedTenantIds(): array
    {
        /** @var array<int, int> $tenantIds */
        $tenantIds = Tenant::query()
            ->where('portfolio_analytics_consent', true)
            ->pluck('id')
            ->map(fn (int $id) => $id)
            ->all();

        return $tenantIds;
    }

    /**
     * Blended roll-up of every branch that belongs to a single tenant.
     *
     * @return array<string, mixed>
     */
    public function tenantSummary(int $tenantId, ?string $date = null, int $days = 30): array
    {
        $branches = Branch::query()->where('tenant_id', $tenantId)->orderBy('id')->get();

        return $this->summarize($branches, $tenantId, 'tenant', $date, $days);
    }

    /**
     * Blended roll-up across tenants that granted cross-tenant consent.
     *
     * @param  array<int, int>  $tenantIds
     * @return array<string, mixed>
     */
    public function portfolioSummary(array $tenantIds, ?string $date = null, int $days = 30): array
    {
        $branches = Branch::query()->whereIn('tenant_id', $tenantIds)->orderBy('id')->get();

        return $this->summarize($branches, null, 'portfolio', $date, $days);
    }

    /**
     * Aggregate branch level KPI entries into blended portfolio totals.
     *
     * @param  Collection<int, Branch>  $branches
     * @return array<string, mixed>
     */
    public function summarize(Collection $branches, ?int $tenantId, string $scope, ?string $date, int $days): array
    {
        /** @var array<int, array<string, mixed>> $branchResults */
        $branchResults = [];
        /** @var int $totalRoomRevenue */
        $totalRoomRevenue = 0;
        /** @var int $totalRoomsSold */
        $totalRoomsSold = 0;
        /** @var int $totalRooms */
        $totalRooms = 0;

        foreach ($branches as $branch) {
            $analytics = new AnalyticsService;
            $analytics->forBranch($branch);

            $revenue = $analytics->getRevenueSummary($days, null, null);
            $occupancyPct = (float) $analytics->getOccupancyPercentage(null, $date);
            $adr = (int) $analytics->getAverageDailyRate($date);
            $revpar = (int) $analytics->getRevPAR($date);

            $branchRooms = (int) Room::forBranch($branch->id)
                ->where('is_active', true)
                ->where('status', '!=', 'out_of_order')
                ->count();
            $branchRoomsSold = $branchRooms > 0 ? (int) round($branchRooms * ($occupancyPct / 100)) : 0;

            $branchResults[] = [
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                'occupancy_pct' => $occupancyPct,
                'adr' => $adr,
                'revpar' => $revpar,
                'total_room_revenue' => (int) $revenue['total_room_revenue'],
            ];

            $totalRoomsSold += $branchRoomsSold;
            $totalRooms += $branchRooms;
            $totalRoomRevenue += (int) $revenue['total_room_revenue'];
        }

        $blendedAdr = $totalRoomsSold > 0 ? (int) round($totalRoomRevenue / $totalRoomsSold) : 0;
        $blendedOccupancyPct = $totalRooms > 0 ? round(($totalRoomsSold / $totalRooms) * 100, 1) : 0.0;
        $blendedRevpar = (int) round($blendedAdr * ($totalRoomsSold / max(1, $totalRooms)));

        /** @var array<string, mixed> */
        $summary = [
            'scope' => $scope,
            'tenant_id' => $tenantId,
            'date' => $date ?? Carbon::now()->toDateString(),
            'days' => $days,
            'branches' => $branchResults,
            'totals' => [
                'branches' => count($branchResults),
                'rooms_sold' => $totalRoomsSold,
                'total_rooms' => $totalRooms,
                'occupancy_pct' => $blendedOccupancyPct,
                'total_room_revenue' => $totalRoomRevenue,
                'adr' => $blendedAdr,
                'revpar' => $blendedRevpar,
            ],
        ];

        return $summary;
    }
}
