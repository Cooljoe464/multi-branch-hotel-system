<?php

namespace App\Jobs;

use App\Services\GuaranteeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ReleaseHoldsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('reservations');
    }

    /**
     * @return array{released: int, overdue_flagged: int}
     */
    public function handle(): array
    {
        $result = (new GuaranteeService)->releaseExpiredHolds();

        Log::info('Released expired guarantee holds.', $result);

        return $result;
    }
}
