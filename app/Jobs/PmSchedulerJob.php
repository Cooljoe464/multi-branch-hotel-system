<?php

namespace App\Jobs;

use App\Models\Branch;
use App\Services\MaintenanceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class PmSchedulerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    /**
     * @return array{branches: int, generated: int}
     */
    public function handle(): array
    {
        $branches = 0;
        $generated = 0;

        Branch::where('is_active', true)->orderBy('id')->chunkById(50, function ($chunk) use (&$branches, &$generated) {
            foreach ($chunk as $branch) {
                $generated += (new MaintenanceService)->schedulePm($branch)['generated'];
                $branches++;
            }
        });

        Log::info('PM scheduler completed.', ['branches' => $branches, 'generated' => $generated]);

        return ['branches' => $branches, 'generated' => $generated];
    }
}
