<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Branch;
use App\Models\DemandForecast;
use App\Models\PriceRecommendation;
use App\Support\BranchTime;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ForecastController extends Controller
{
    use EnsuresBranchAccess;

    public function index(Branch $branch, Request $request): Response
    {
        $this->ensureBranchAccess($branch);

        $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
        ]);

        $today = BranchTime::today($branch);
        $fromInput = $request->string('from')->value();
        $toInput = $request->string('to')->value();
        $from = $fromInput !== '' ? $fromInput : $today;
        $to = $toInput !== '' ? $toInput : Carbon::parse($today)->addDays(30)->toDateString();

        $forecasts = DemandForecast::forBranch($branch->id)
            ->whereBetween('stay_date', [$from, $to])
            ->orderBy('stay_date')
            ->orderByDesc('generated_on')
            ->get()
            ->unique('stay_date')
            ->map(fn (DemandForecast $f) => [
                'stay_date' => $f->stay_date->toDateString(),
                'p_demand' => $f->p_demand,
                'expected_rooms' => $f->expected_rooms,
                'model_version' => $f->model_version,
                'generated_on' => $f->generated_on->toDateString(),
                'stale' => $f->stale(),
            ])
            ->values()
            ->all();

        $proposals = PriceRecommendation::forBranch($branch->id)
            ->with('roomType')
            ->whereBetween('stay_date', [$from, $to])
            ->open()
            ->orderBy('stay_date')
            ->limit(200)
            ->get()
            ->map(fn (PriceRecommendation $r) => [
                'id' => $r->id,
                'stay_date' => $r->stay_date->toDateString(),
                'room_type' => $r->roomType->name,
                'recommended_minor' => $r->recommended_minor,
                'current_minor' => $r->current_minor,
                'deviation_bps' => $r->deviationBps(),
                'status' => $r->status,
            ])
            ->all();

        return Inertia::render('revenue/Forecast', [
            'branch' => $branch->only(['id', 'name', 'currency_code']),
            'from' => $from,
            'to' => $to,
            'forecasts' => $forecasts,
            'proposals' => $proposals,
        ]);
    }
}
