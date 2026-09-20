<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Services\AuditFlagService;
use App\Services\NightAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RunNightAudit extends Command
{
    protected $signature = 'night-audit {--branch= : Branch ID to audit (all active if omitted)} {--date= : Business date to audit (yesterday if omitted)}';

    protected $description = 'Post room charges, taxes, and close daily ledgers for the previous business day';

    public function handle(NightAuditService $auditService, AuditFlagService $auditFlagService): int
    {
        $branchId = $this->option('branch');
        $dateOption = $this->option('date');

        $businessDate = $dateOption
            ? Carbon::parse($dateOption)
            : Carbon::now()->subDay();

        $branches = $branchId
            ? Branch::where('id', $branchId)->where('is_active', true)->get()
            : Branch::where('is_active', true)->get();

        if ($branches->isEmpty()) {
            $this->error('No active branches found.');

            return self::FAILURE;
        }

        $this->info("Running night audit for {$businessDate->format('Y-m-d')}...");
        $this->newLine();

        $successCount = 0;
        $failCount = 0;

        foreach ($branches as $branch) {
            $this->info("Processing: {$branch->name} ({$branch->code})");

            try {
                $auditService->forBranch($branch);

                $unclosed = $auditService->getUnclosedLedger($businessDate);
                if ($unclosed && $unclosed->status === 'completed') {
                    $this->line("  Already completed for {$businessDate->format('Y-m-d')}, skipping.");

                    continue;
                }

                $ledger = $auditService->closeDailyLedger($businessDate);

                $this->line("  Rooms posted: {$ledger->rooms_posted}");
                $this->line("  Room revenue: {$ledger->total_room_revenue} cents");
                $this->line("  Tax: {$ledger->total_tax} cents");
                $this->line("  Other charges: {$ledger->total_other_charges} cents");
                $this->line("  Payments: {$ledger->total_payments} cents");
                $this->line("  Net revenue: {$ledger->net_revenue} cents");

                if (! empty($ledger->errors)) {
                    $this->warn('  Errors encountered: '.count($ledger->errors));
                    foreach ($ledger->errors as $error) {
                        $this->line("    - Reservation #{$error['reservation_id']}: {$error['error']}");
                    }
                }

                $this->info("  Status: {$ledger->status}");

                $flags = $auditFlagService->generate($branch, $businessDate);
                if (! empty($flags)) {
                    $this->warn('  Audit flags: '.count($flags).' generated');
                }

                $successCount++;
            } catch (\Throwable $e) {
                $this->error("  Failed: {$e->getMessage()}");
                $failCount++;
            }

            $this->newLine();
        }

        $this->info("Night audit complete. {$successCount} succeeded, {$failCount} failed.");

        return $failCount > 0 ? self::FAILURE : self::SUCCESS;
    }
}
