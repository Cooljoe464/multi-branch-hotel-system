<?php

namespace App\Jobs;

use App\Models\Branch;
use App\Services\AnomalyService;
use App\Support\BranchTime;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Hourly anomaly sweep. One branch runs at a time (findings are
 * branch-scoped); re-runs are no-ops thanks to finding dedupe.
 */
class AnomalyScanJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(private ?int $branchId = null, private ?string $date = null)
    {
        $this->onQueue('ml');
    }

    public function uniqueId(): string
    {
        return 'anomaly:'.($this->branchId ?? 'all').':'.($this->date ?? 'today');
    }

    /**
     * @return array{scanned: int, findings: int}
     */
    public function handle(): array
    {
        if ($this->branchId !== null) {
            $branch = Branch::findOrFail($this->branchId);
            $findings = (new AnomalyService)->scanBranch($branch, $this->date ?? BranchTime::today($branch));

            Log::info('Anomaly scan completed.', ['branch_id' => $branch->id, 'findings' => count($findings)]);

            return ['scanned' => 1, 'findings' => count($findings)];
        }

        $result = (new AnomalyService)->scanAll($this->date);

        Log::info('Anomaly scan completed.', $result);

        return $result;
    }
}
