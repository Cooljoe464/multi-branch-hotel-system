<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DrDrill;
use App\Services\PartitionManager;
use App\Services\ReadRouter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Inertia\Response;

class DatabaseController extends Controller
{
    public function index(): Response
    {
        $manager = new PartitionManager;
        $lastDrill = DrDrill::latest('drill_date')->first();

        return Inertia::render('admin/Database', [
            'replica' => [
                'host' => config('database.connections.replica.host'),
                'lag_seconds' => ReadRouter::lagSeconds(),
                'stale_threshold' => ReadRouter::LAG_ALERT_SECONDS,
            ],
            'partitions' => $manager->readiness(),
            'last_drill' => $lastDrill === null ? null : [
                'drill_date' => $lastDrill->drill_date->toDateString(),
                'mode' => $lastDrill->mode,
                'status' => $lastDrill->status,
                'rto_minutes' => $lastDrill->rto_minutes,
                'rpo_minutes' => $lastDrill->rpo_minutes,
            ],
            'drill_fresh' => DrDrill::isFresh(),
        ]);
    }

    public function drill(): RedirectResponse
    {
        $exit = Artisan::call('dr:smoke', ['--dry-run' => true]);

        return redirect()->route('admin.database.index')
            ->with('toast', ['type' => $exit === 0 ? 'success' : 'error', 'message' => $exit === 0 ? 'DR smoke passed and recorded.' : 'DR smoke FAILED — see drill log.']);
    }
}
