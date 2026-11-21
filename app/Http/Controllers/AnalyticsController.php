<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\AnalyticsService;
use App\Services\PortfolioAnalyticsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $branch = $user->currentBranch;
        abort_unless($branch !== null, 403, 'No branch context set.');

        $analytics = (new AnalyticsService)->forBranch($branch);

        $daysInput = $request->input('days');
        $days = is_numeric($daysInput) ? (int) $daysInput : 30;

        $startDateInput = $request->string('start_date')->value();
        $endDateInput = $request->string('end_date')->value();
        $startDate = $startDateInput !== '' ? $startDateInput : null;
        $endDate = $endDateInput !== '' ? $endDateInput : null;

        if ($startDate && $endDate) {
            $days = (int) Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate));
        }

        $kpi = $analytics->getKpiSummary(null, $days, $startDate, $endDate);
        $revenue = $analytics->getRevenueSummary($days, $startDate, $endDate);
        $occupancyTrend = $analytics->getOccupancyTrend($days, $startDate, $endDate);
        $roomTypePerformance = $analytics->getRoomTypePerformance($days, $startDate, $endDate);

        return Inertia::render('analytics/Index', [
            'kpi' => $kpi,
            'revenue' => $revenue,
            'occupancyTrend' => $occupancyTrend,
            'roomTypePerformance' => $roomTypePerformance,
            'days' => $days,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'branch' => $branch,
        ]);
    }

    /**
     * Portfolio roll-up: blended occupancy, ADR and RevPAR across the
     * branches of a tenant, or across consented tenants for the platform
     * portfolio. Cross-tenant aggregation requires the target tenant to
     * have opted in via tenant-level portfolio consent.
     */
    public function portfolio(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);
        abort_unless($user->can('analytics.view'), 403);

        $days = is_numeric($request->input('days')) ? (int) $request->input('days') : 30;
        $startDate = $request->string('start_date')->value();
        $endDate = $request->string('end_date')->value();
        $date = $startDate !== '' && $endDate !== '' ? Carbon::parse($endDate)->toDateString() : null;

        $requestedTenantId = $request->filled('tenant_id') ? (int) $request->input('tenant_id') : null;
        $isGlobalAdmin = (bool) $user->is_global_admin;

        $portfolio = app(PortfolioAnalyticsService::class);

        if ($isGlobalAdmin && $requestedTenantId === null) {
            $summary = $portfolio->portfolioSummary($portfolio->consentedTenantIds(), $date, $days);

            return response()->json($summary);
        }

        $tenantId = $isGlobalAdmin
            ? (int) ($requestedTenantId ?? $user->currentBranch?->tenant_id)
            : ($requestedTenantId ?? $user->currentBranch?->tenant_id);

        $tenant = Tenant::query()->find($tenantId);

        abort_unless($tenant !== null, 404);

        if (! $isGlobalAdmin && $requestedTenantId !== null && (int) $tenant->id !== (int) $user->currentBranch?->tenant_id) {
            abort_unless($tenant->portfolio_analytics_consent, 403, 'Cross-tenant portfolio reporting consent is required.');
        }

        $summary = $portfolio->tenantSummary((int) $tenant->id, $date, $days);

        return response()->json($summary);
    }
}
