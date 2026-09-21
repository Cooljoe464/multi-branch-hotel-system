<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Services\CommissionService;
use Illuminate\Console\Command;

class CommissionsBackfill extends Command
{
    protected $signature = 'commissions:backfill {--days=90 : Lookback window in days} {--branch= : Backfill a single branch id}';

    protected $description = 'Accrue OTA/agent commissions for checked-out stays in the last N days (idempotent).';

    public function handle(): int
    {
        $days = max(1, $this->integerOption('days', 90));
        $branchId = $this->option('branch');
        $branchId = is_numeric($branchId) ? (int) $branchId : null;

        $branches = Branch::when($branchId, fn ($q) => $q->where('id', $branchId))->orderBy('id')->get();

        $total = 0;
        foreach ($branches as $branch) {
            $result = (new CommissionService)->backfill($branch, $days);
            $total += $result['accrued'];
            $this->info("Branch {$branch->id}: accrued {$result['accrued']}, skipped {$result['skipped']}.");
        }

        $this->info("Done. {$total} accruals.");

        return self::SUCCESS;
    }

    private function integerOption(string $key, int $default): int
    {
        $value = $this->option($key);

        return is_numeric($value) ? (int) $value : $default;
    }
}
