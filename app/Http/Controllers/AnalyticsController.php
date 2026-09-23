<?php

namespace App\Http\Controllers;

use App\Services\AnalyticsService;
use Carbon\Carbon;
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
}
