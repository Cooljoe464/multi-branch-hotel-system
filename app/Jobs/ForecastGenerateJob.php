<?php

namespace App\Jobs;

use App\Models\Branch;
use App\Models\RoomType;
use App\Services\ForecastingService;
use App\Services\PricingRecommender;
use App\Support\BranchTime;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Nightly forecast + proposal generation for the booking window.
 * Proposals are drafts until approved; the model never touches
 * live rates by itself.
 */
class ForecastGenerateJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(private ?int $branchId = null, private int $horizonDays = 90)
    {
        $this->onQueue('ml');
    }

    public function uniqueId(): string
    {
        return 'forecast:'.($this->branchId ?? 'all');
    }

    /**
     * @return array{branches: int, forecasts: int, proposals: int}
     */
    public function handle(): array
    {
        $branches = $this->branchId !== null
            ? Branch::where('id', $this->branchId)->where('is_active', true)->get()
            : Branch::where('is_active', true)->orderBy('id')->get();

        $forecasts = 0;
        $proposals = 0;
        $forecasting = new ForecastingService;
        $recommender = new PricingRecommender;

        foreach ($branches as $branch) {
            $today = BranchTime::today($branch);
            $to = Carbon::parse($today)->addDays($this->horizonDays)->toDateString();

            foreach ($forecasting->generateRange($branch, $today, $to) as $forecast) {
                $forecasts++;

                foreach (RoomType::forBranch($branch->id)->active()->get() as $roomType) {
                    $recommender->propose($branch, $roomType, $forecast->stay_date->toDateString());
                    $proposals++;
                }
            }
        }

        Log::info('Forecast generation completed.', ['branches' => $branches->count(), 'forecasts' => $forecasts, 'proposals' => $proposals]);

        return ['branches' => $branches->count(), 'forecasts' => $forecasts, 'proposals' => $proposals];
    }

    public function backfill(Branch $branch): int
    {
        $today = BranchTime::today($branch);
        $from = Carbon::parse($today)->subDays(90)->toDateString();

        return count((new ForecastingService)->generateRange($branch, $from, $today, backfill: true));
    }
}
