<?php

use App\Jobs\AnomalyScanJob;
use App\Jobs\CutoffJob;
use App\Jobs\ForecastGenerateJob;
use App\Jobs\HkScheduleJob;
use App\Jobs\MonitorStuckQueuesJob;
use App\Jobs\NightlyReconciliationJob;
use App\Jobs\PartitionManagerJob;
use App\Jobs\PmSchedulerJob;
use App\Jobs\PredictiveMaintenanceJob;
use App\Jobs\ReleaseHoldsJob;
use App\Jobs\RetentionRunJob;
use App\Jobs\SnapshotRevenueJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── Existing ──────────────────────────────────
Schedule::command('night-audit')->dailyAt('02:00')->onOneServer()->withoutOverlapping(120);

// ── Observability ─────────────────────────────
Schedule::command('horizon:snapshot')->everyFiveMinutes()->onOneServer();
Schedule::job(new MonitorStuckQueuesJob)->everyFiveMinutes()->onOneServer()->withoutOverlapping(10);

// ── Guarantees ──────────────────────────────────
Schedule::job(new ReleaseHoldsJob)->everyTenMinutes()->onOneServer()->withoutOverlapping(15);

// ── Groups ──────────────────────────────────────
Schedule::job(new CutoffJob)->dailyAt('01:30')->onOneServer()->withoutOverlapping(120);

// ── Channels ────────────────────────────────────
Schedule::job(new NightlyReconciliationJob)->dailyAt('02:30')->onOneServer()->withoutOverlapping(120);

// ── Revenue ─────────────────────────────────────
Schedule::job(new SnapshotRevenueJob)->dailyAt('02:45')->onOneServer()->withoutOverlapping(120);

// ── Maintenance ─────────────────────────────────
Schedule::job(new PmSchedulerJob)->dailyAt('05:00')->onOneServer()->withoutOverlapping(120);

// ── Privacy ─────────────────────────────────────
Schedule::job(new RetentionRunJob)->dailyAt('03:15')->onOneServer()->withoutOverlapping(180);

// ── Backups ───────────────────────────────────
Schedule::command('backup:clean')->daily()->at('03:00')->onOneServer()->withoutOverlapping(120);
Schedule::command('backup:run --only-db')->daily()->at('03:30')->onOneServer()->withoutOverlapping(180);
Schedule::command('backup:run')->weekly()->sundays()->at('04:00')->onOneServer()->withoutOverlapping(300);

// ── Platform ──────────────────────────────────
Schedule::job(new PartitionManagerJob)->monthly()->onOneServer()->withoutOverlapping(120);

// ── Intelligence ──────────────────────────────
Schedule::job(new AnomalyScanJob)->hourly()->onOneServer()->withoutOverlapping(50);
Schedule::job(new ForecastGenerateJob)->dailyAt('01:00')->onOneServer()->withoutOverlapping(180);
Schedule::job(new PredictiveMaintenanceJob)->dailyAt('01:30')->onOneServer()->withoutOverlapping(180);
Schedule::job(new HkScheduleJob)->dailyAt('04:00')->onOneServer()->withoutOverlapping(180);
