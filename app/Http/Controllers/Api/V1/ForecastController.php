<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\DemandForecast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ForecastController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var Branch $branch */
        $branch = $request->attributes->get('branch');

        $request->validate([
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
        ]);

        $from = $request->string('from')->value();
        $to = $request->string('to')->value();

        $data = DemandForecast::forBranch($branch->id)
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

        return response()->json(['data' => $data]);
    }
}
