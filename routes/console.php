<?php

use App\Jobs\CutoffJob;
use App\Jobs\MonitorStuckQueuesJob;
use App\Jobs\NightlyReconciliationJob;
use App\Jobs\PmSchedulerJob;
use App\Jobs\ReleaseHoldsJob;
use App\Jobs\SnapshotRevenueJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── Existing ──────────────────────────────────
Schedule::command('night-audit')->dailyAt('02:00');

// ── Observability ─────────────────────────────
Schedule::job(new MonitorStuckQueuesJob)->everyFiveMinutes();

// ── Guarantees ──────────────────────────────────
Schedule::job(new ReleaseHoldsJob)->everyTenMinutes();

// ── Groups ──────────────────────────────────────
Schedule::job(new CutoffJob)->dailyAt('01:30');

// ── Channels ────────────────────────────────────
Schedule::job(new NightlyReconciliationJob)->dailyAt('02:30');

// ── Revenue ─────────────────────────────────────
Schedule::job(new SnapshotRevenueJob)->dailyAt('02:45');

// ── Maintenance ─────────────────────────────────
Schedule::job(new PmSchedulerJob)->dailyAt('05:00');

// ── Backups ───────────────────────────────────
Schedule::command('backup:clean')->daily()->at('03:00');
Schedule::command('backup:run --only-db')->daily()->at('03:30');
Schedule::command('backup:run')->weekly()->sundays()->at('04:00');
