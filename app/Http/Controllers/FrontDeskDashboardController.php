<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Models\Room;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FrontDeskDashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $branch = $user->currentBranch;
        abort_unless($branch !== null, 403, 'No branch context set.');

        $branchId = $branch->id;
        $today = now()->toDateString();

        $roomStatusCounts = Room::forBranch($branchId)
            ->active()
            ->select('status', \DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $todayArrivals = Reservation::forBranch($branchId)
            ->whereIn('status', ['pending', 'confirmed', 'reserved'])
            ->where('check_in_date', $today)
            ->with('room:id,number,name,branch_id')
            ->orderBy('check_in_date')
            ->get();

        $todayDepartures = Reservation::forBranch($branchId)
            ->checkedIn()
            ->where('check_out_date', $today)
            ->with('room:id,number,name,branch_id')
            ->orderBy('check_out_date')
            ->get();

        $inHouseGuests = Reservation::forBranch($branchId)
            ->checkedIn()
            ->where('check_in_date', '<=', $today)
            ->where('check_out_date', '>', $today)
            ->with('room:id,number,name,branch_id')
            ->orderBy('check_out_date')
            ->get();

        return Inertia::render('front-desk/Dashboard', [
            'branch' => $branch,
            'roomStatusCounts' => [
                'available' => $roomStatusCounts['available'] ?? 0,
                'occupied' => $roomStatusCounts['occupied'] ?? 0,
                'dirty' => $roomStatusCounts['dirty'] ?? 0,
                'out_of_order' => $roomStatusCounts['out_of_order'] ?? 0,
            ],
            'todayArrivals' => $todayArrivals,
            'todayDepartures' => $todayDepartures,
            'inHouseGuests' => $inHouseGuests,
        ]);
    }
}
