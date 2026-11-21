<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Branch;
use App\Models\FuelLog;
use App\Models\Generator;
use App\Models\GeneratorRun;
use App\Models\UtilityMeter;
use App\Services\GeneratorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GeneratorController extends Controller
{
    use EnsuresBranchAccess;

    public function __construct(private GeneratorService $generatorService) {}

    public function index(Request $request, Branch $branch): Response
    {
        $this->ensureBranchAccess($branch);

        $generators = Generator::where('branch_id', $branch->id)
            ->with(['runs' => fn ($q) => $q->orderByDesc('started_at')->limit(5)])
            ->get();

        $activeRuns = GeneratorRun::whereIn('generator_id', $generators->pluck('id'))
            ->whereNull('stopped_at')
            ->with(['generator', 'operator'])
            ->get();

        return Inertia::render('facilities/Generators', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'generators' => $generators,
            'activeRuns' => $activeRuns,
        ]);
    }

    public function start(Request $request, Branch $branch, Generator $generator): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($generator->branch_id === $branch->id, 404);

        $request->validate([
            'reason' => 'required|string|max:255',
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $this->generatorService->startRun(
            $generator,
            $user,
            $request->string('reason')->value(),
        );

        return $this->flashSuccess("Generator {$generator->name} started.");
    }

    public function stop(Request $request, Branch $branch, GeneratorRun $run): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($run->generator->branch_id === $branch->id, 404);

        $request->validate([
            'end_meter_hours' => 'required|numeric|gte:'.$run->start_meter_hours,
            'fuel_consumed_liters' => 'nullable|numeric|min:0',
        ]);

        $fuelConsumed = $request->filled('fuel_consumed_liters') ? $request->float('fuel_consumed_liters') : null;

        $stopped = $this->generatorService->stopRun(
            $run,
            $request->float('end_meter_hours'),
            $fuelConsumed,
        );

        $msg = "Generator stopped. Total run time: {$stopped->run_hours} hrs.";

        if ($fuelConsumed !== null && $this->generatorService->detectFuelAnomaly($run->generator, $fuelConsumed, (float) $stopped->run_hours)) {
            $msg .= ' Warning: Abnormal fuel consumption detected.';
        }

        return $this->flashSuccess($msg);
    }

    public function fuel(Request $request, Branch $branch): Response
    {
        $this->ensureBranchAccess($branch);

        $logs = FuelLog::where('branch_id', $branch->id)
            ->with(['generator', 'author'])
            ->orderByDesc('logged_at')
            ->paginate(25)
            ->withQueryString();

        $generators = Generator::where('branch_id', $branch->id)->get(['id', 'name', 'kva_rating']);

        return Inertia::render('facilities/FuelTracking', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'fuelLogs' => $logs,
            'generators' => $generators,
        ]);
    }

    public function logFuel(Request $request, Branch $branch): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $request->validate([
            'generator_id' => 'nullable|exists:generators,id',
            'log_type' => 'required|in:delivery,consumption',
            'liters' => 'required|numeric|min:0.1',
            'cost_per_liter_minor' => 'required|integer|min:0',
            'supplier_name' => 'nullable|string|max:255',
            'invoice_number' => 'nullable|string|max:100',
            'dipstick_level_cm' => 'nullable|numeric|min:0',
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $this->generatorService->logFuel($branch, $request->all(), $user);

        return $this->flashSuccess('Fuel log recorded.');
    }

    public function meters(Request $request, Branch $branch): Response
    {
        $this->ensureBranchAccess($branch);

        $meters = UtilityMeter::where('branch_id', $branch->id)->get();

        return Inertia::render('facilities/Meters', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'meters' => $meters,
        ]);
    }

    public function updateMeter(Request $request, Branch $branch, UtilityMeter $meter): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($meter->branch_id === $branch->id, 404);

        $request->validate([
            'current_reading' => 'required|numeric|gte:'.$meter->current_reading,
        ]);

        $meter->update(['current_reading' => $request->float('current_reading')]);

        return $this->flashSuccess('Meter reading updated.');
    }
}
