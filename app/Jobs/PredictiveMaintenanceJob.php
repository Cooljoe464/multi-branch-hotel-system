<?php

namespace App\Jobs;

use App\Models\Branch;
use App\Services\PredictiveMaintenanceService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class PredictiveMaintenanceJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(private ?int $branchId = null)
    {
        $this->onQueue('ml');
    }

    public function uniqueId(): string
    {
        return 'predictive:'.($this->branchId ?? 'all');
    }

    /**
     * @return array{branches: int, scored: int, drafts: int}
     */
    public function handle(): array
    {
        $branches = $this->branchId !== null
            ? Branch::where('id', $this->branchId)->where('is_active', true)->get()
            : Branch::where('is_active', true)->orderBy('id')->get();

        $service = new PredictiveMaintenanceService;
        $scored = 0;
        $drafts = 0;

        foreach ($branches as $branch) {
            $result = $service->scoreBranch($branch);
            $scored += $result['scored'];
            $drafts += $result['drafts'];
        }

        Log::info('Predictive maintenance scored.', ['branches' => $branches->count(), 'scored' => $scored, 'drafts' => $drafts]);

        return ['branches' => $branches->count(), 'scored' => $scored, 'drafts' => $drafts];
    }
}
