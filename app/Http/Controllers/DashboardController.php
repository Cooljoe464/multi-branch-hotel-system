<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Services\AnalyticsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
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

        $roomStatusCounts = Room::forBranch($branch->id)
            ->active()
            ->select('status', \DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return Inertia::render('Dashboard', [
            'kpi' => $kpi,
            'branch' => $branch,
            'days' => $days,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'roomStatusCounts' => [
                'available' => $roomStatusCounts['available'] ?? 0,
                'occupied' => $roomStatusCounts['occupied'] ?? 0,
                'dirty' => $roomStatusCounts['dirty'] ?? 0,
                'out_of_order' => $roomStatusCounts['out_of_order'] ?? 0,
            ],
        ]);
    }
}
