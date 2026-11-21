<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\FuelLog;
use App\Models\Generator;
use App\Models\GeneratorRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Diesel generator lifecycle and fuel tracking for hotel branches.
 * Handles start/stop runs, fuel logging, cost analysis, and anomaly detection.
 */
class GeneratorService
{
    /**
     * Start a generator run. Only one open run per generator.
     */
    public function startRun(Generator $generator, User $operator, string $reason): GeneratorRun
    {
        return DB::transaction(function () use ($generator, $operator, $reason) {
            $openRun = GeneratorRun::where('generator_id', $generator->id)
                ->whereNull('stopped_at')
                ->lockForUpdate()
                ->first();

            if ($openRun) {
                throw new LogicException('Generator is already running.');
            }

            $run = GeneratorRun::create([
                'generator_id' => $generator->id,
                'started_at' => now(),
                'start_meter_hours' => $generator->current_meter_hours,
                'reason' => $reason,
                'operator_id' => $operator->id,
            ]);

            $generator->update(['status' => 'running']);

            return $run;
        });
    }

    /**
     * Stop a generator run, calculate run hours.
     */
    public function stopRun(GeneratorRun $run, float $endMeterHours, ?float $fuelConsumed = null): GeneratorRun
    {
        return DB::transaction(function () use ($run, $endMeterHours, $fuelConsumed) {
            $locked = GeneratorRun::where('id', $run->id)->lockForUpdate()->firstOrFail();

            if ($locked->stopped_at !== null) {
                throw new LogicException('Run is already stopped.');
            }

            $runHours = $endMeterHours - $locked->start_meter_hours;

            $locked->update([
                'stopped_at' => now(),
                'end_meter_hours' => $endMeterHours,
                'run_hours' => $runHours,
                'fuel_consumed_liters' => $fuelConsumed,
            ]);

            $locked->generator->update([
                'current_meter_hours' => $endMeterHours,
                'status' => 'idle',
            ]);

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * Log a fuel delivery or consumption event.
     *
     * @param  array<string, mixed>  $data
     */
    public function logFuel(Branch $branch, array $data, User $user): FuelLog
    {
        $totalCost = (int) (($data['liters'] ?? 0) * ($data['cost_per_liter_minor'] ?? 0));

        return FuelLog::create([
            'branch_id' => $branch->id,
            'generator_id' => $data['generator_id'] ?? null,
            'log_type' => $data['log_type'] ?? 'delivery',
            'liters' => $data['liters'],
            'cost_per_liter_minor' => $data['cost_per_liter_minor'] ?? 0,
            'total_cost_minor' => $totalCost,
            'supplier_name' => $data['supplier_name'] ?? null,
            'invoice_number' => $data['invoice_number'] ?? null,
            'dipstick_level_cm' => $data['dipstick_level_cm'] ?? null,
            'logged_by' => $user->id,
            'logged_at' => now(),
        ]);
    }

    /**
     * Detect fuel consumption anomaly by comparing reported vs expected.
     * Returns true if deviation exceeds 20% (possible fuel theft).
     */
    public function detectFuelAnomaly(Generator $generator, float $reportedConsumption, float $runHours): bool
    {
        $expected = $generator->liters_per_hour_rated * $runHours;

        if ($expected <= 0) {
            return false;
        }

        $deviation = abs($reportedConsumption - $expected) / $expected;

        return $deviation > 0.20;
    }

    /**
     * Calculate average fuel cost per running hour over a lookback period.
     */
    public function costPerHour(Generator $generator, int $lookbackDays = 30): float
    {
        $totalCost = FuelLog::where('generator_id', $generator->id)
            ->where('logged_at', '>=', now()->subDays($lookbackDays))
            ->sum('total_cost_minor');

        $totalHours = GeneratorRun::where('generator_id', $generator->id)
            ->where('started_at', '>=', now()->subDays($lookbackDays))
            ->whereNotNull('run_hours')
            ->sum('run_hours');

        if ($totalHours <= 0) {
            return 0.0;
        }

        return round($totalCost / $totalHours, 2);
    }
}
