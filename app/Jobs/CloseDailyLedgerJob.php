<?php

namespace App\Jobs;

use App\Models\Branch;
use App\Models\DailyLedger;
use App\Services\NightAuditService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class CloseDailyLedgerJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(
        public int $branchId,
        public string $businessDate,
    ) {
        $this->onQueue('night-audit');
    }

    public function handle(NightAuditService $nightAuditService): DailyLedger
    {
        $branch = Branch::findOrFail($this->branchId);
        $businessDate = Carbon::parse($this->businessDate);

        return $nightAuditService->forBranch($branch)->closeDailyLedger($businessDate);
    }
}
