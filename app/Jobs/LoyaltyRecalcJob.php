<?php

namespace App\Jobs;

use App\Services\LoyaltyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Nightly sweep that promotes loyalty members whose checked-out nights
 * have crossed a tier threshold.
 */
class LoyaltyRecalcJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('reports');
    }

    /**
     * @return array{checked: int, upgraded: int}
     */
    public function handle(LoyaltyService $loyalty): array
    {
        return $loyalty->recalcAll();
    }
}
