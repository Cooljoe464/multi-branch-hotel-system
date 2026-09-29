<?php

namespace App\Jobs;

use App\Models\Branch;
use App\Services\HkSchedulerService;
use App\Support\BranchTime;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class HkScheduleJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(private ?int $branchId = null)
    {
        $this->onQueue('ml');
    }

    public function uniqueId(): string
    {
        return 'hk-schedule:'.($this->branchId ?? 'all');
    }

    /**
     * @return array{branches: int, schedules: int}
     */
    public function handle(): array
    {
        $branches = $this->branchId !== null
            ? Branch::where('id', $this->branchId)->where('is_active', true)->get()
            : Branch::where('is_active', true)->orderBy('id')->get();

        $service = new HkSchedulerService;
        $schedules = 0;

        foreach ($branches as $branch) {
            $tomorrow = Carbon::parse(BranchTime::today($branch))->addDay()->toDateString();
            $service->plan($branch, $tomorrow);
            $schedules++;
        }

        Log::info('Housekeeping schedules drafted.', ['branches' => $branches->count(), 'schedules' => $schedules]);

        return ['branches' => $branches->count(), 'schedules' => $schedules];
    }
}
