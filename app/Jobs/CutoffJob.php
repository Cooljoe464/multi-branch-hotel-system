<?php

namespace App\Jobs;

use App\Models\GroupBlock;
use App\Services\GroupBlockService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class CutoffJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('reservations');
    }

    /**
     * @return array{released_blocks: int, released_nights: int}
     */
    public function handle(): array
    {
        $today = Carbon::today()->toDateString();
        $service = new GroupBlockService;
        $blocks = 0;
        $nights = 0;

        GroupBlock::whereIn('status', [GroupBlock::STATUS_TENTATIVE, GroupBlock::STATUS_DEFINITE])
            ->where('cutoff_date', '<', $today)
            ->orderBy('id')
            ->chunkById(100, function ($chunk) use ($service, &$blocks, &$nights) {
                foreach ($chunk as $block) {
                    $released = $service->cutoffRelease($block);

                    if ($released > 0) {
                        $blocks++;
                        $nights += $released;
                    }
                }
            });

        Log::info('Group block cut-off sweep completed.', ['blocks' => $blocks, 'nights' => $nights]);

        return ['released_blocks' => $blocks, 'released_nights' => $nights];
    }
}
