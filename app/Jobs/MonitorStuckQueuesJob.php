<?php

namespace App\Jobs;

use App\Events\QueueAlertRaised;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Watches for stuck night-audit work and failed-job growth.
 * Runs every 5 minutes on the maintenance queue (see routes/console.php).
 */
class MonitorStuckQueuesJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    public function handle(): void
    {
        $failed = $this->failedJobCount();

        if ($failed >= 100) {
            $this->raise('failed_jobs_high', "{$failed} failed jobs need attention.");
        }

        $staleAudits = (int) DB::table('daily_ledgers')
            ->where('status', 'in_progress')
            ->where('started_at', '<', now()->subMinutes(30))
            ->count();

        if ($staleAudits > 0) {
            $this->raise('night_audit_stuck', "{$staleAudits} night audit(s) stuck in progress for 30+ minutes.");
        }
    }

    private function raise(string $kind, string $detail): void
    {
        Log::warning("Queue alert: {$kind} — {$detail}");

        event(new QueueAlertRaised($kind, $detail));
    }

    private function failedJobCount(): int
    {
        try {
            return (int) DB::table('failed_jobs')->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
