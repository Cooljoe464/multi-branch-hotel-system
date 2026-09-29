<?php

namespace App\Services;

use App\Events\ForecastReady;
use App\Models\Branch;
use App\Models\DemandForecast;
use App\Services\Forecasting\Forecaster;
use App\Services\Forecasting\HeuristicForecaster;
use App\Support\BranchTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Nightly demand forecasts. The primary driver may be a remote
 * model; any failure falls back to heuristics and says so in the
 * stored version — a down model degrades the forecast, never the
 * stay. Rows are append-only history.
 */
class ForecastingService
{
    public function generate(Branch $branch, string $stayDate, ?Forecaster $driver = null, bool $backfill = false): DemandForecast
    {
        $driver ??= new HeuristicForecaster;
        $fallback = false;

        try {
            $result = $driver->forecast($branch, $stayDate);
        } catch (\Throwable $e) {
            Log::warning('Forecaster failed; heuristic fallback used.', [
                'branch_id' => $branch->id,
                'stay_date' => $stayDate,
                'error' => $e->getMessage(),
            ]);

            $result = (new HeuristicForecaster)->forecast($branch, $stayDate);
            $fallback = true;
        }

        $version = $backfill ? 'backfill' : $result['model_version'];

        if ($fallback) {
            $version = 'heuristic-fallback';
        }

        $forecast = DemandForecast::updateOrCreate(
            [
                'branch_id' => $branch->id,
                'stay_date' => $stayDate,
                'generated_on' => BranchTime::today($branch),
            ],
            [
                'p_demand' => min(1.0, max(0.0, (float) $result['p_demand'])),
                'expected_rooms' => max(0, (int) $result['expected_rooms']),
                'features' => array_merge($result['features'], $fallback ? ['fallback' => true] : []),
                'model_version' => $version,
            ],
        );

        event(new ForecastReady($forecast));

        return $forecast->fresh() ?? $forecast;
    }

    /**
     * @return list<DemandForecast>
     */
    public function generateRange(Branch $branch, string $from, string $to, bool $backfill = false): array
    {
        $forecasts = [];

        foreach ((new AvailabilityService)->nights($from, Carbon::parse($to)->addDay()->toDateString()) as $date) {
            $forecasts[] = $this->generate($branch, $date, null, $backfill);
        }

        return $forecasts;
    }

    public function latest(Branch $branch, string $stayDate): ?DemandForecast
    {
        return DemandForecast::forBranch($branch->id)
            ->where('stay_date', $stayDate)
            ->orderByDesc('generated_on')
            ->first();
    }
}
