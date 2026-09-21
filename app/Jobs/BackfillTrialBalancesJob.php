<?php

namespace App\Jobs;

use App\Models\Branch;
use App\Models\JournalEntry;
use App\Services\TrialBalanceService;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * One-shot trial close for every date with journal history. Idempotent:
 * close() is updateOrCreate, so re-runs rewrite identical rows.
 */
class BackfillTrialBalancesJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    /**
     * @return array{days: int}
     */
    public function handle(?TrialBalanceService $trialBalances = null): array
    {
        $trialBalances ??= app(TrialBalanceService::class);
        $days = 0;

        Branch::query()->chunkById(100, function ($branches) use ($trialBalances, &$days) {
            foreach ($branches as $branch) {
                $dates = JournalEntry::forBranch($branch->id)
                    ->distinct()
                    ->pluck('business_date');

                foreach ($dates as $date) {
                    $day = $date instanceof CarbonInterface
                        ? $date->toDateString()
                        : (is_string($date) ? Carbon::parse($date)->toDateString() : null);

                    if ($day === null) {
                        continue;
                    }

                    $trialBalances->close($branch, $day);
                    $days++;
                }
            }
        });

        return ['days' => $days];
    }
}
