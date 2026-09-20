<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── Existing ──────────────────────────────────
Schedule::command('night-audit')->dailyAt('02:00');

// ── Backups ───────────────────────────────────
Schedule::command('backup:clean')->daily()->at('03:00');
Schedule::command('backup:run --only-db')->daily()->at('03:30');
Schedule::command('backup:run')->weekly()->sundays()->at('04:00');
