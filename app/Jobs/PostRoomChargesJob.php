<?php

namespace App\Jobs;

use App\Models\Branch;
use App\Services\NightAuditService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class PostRoomChargesJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public int $branchId,
        public string $businessDate,
    ) {
        $this->onQueue('night-audit');
    }

    /**
     * @return array{posted: int, errors: list<array{reservation_id: int, error: string}>, total_room_revenue: int, total_tax: int}
     */
    public function handle(NightAuditService $nightAuditService): array
    {
        $branch = Branch::findOrFail($this->branchId);
        $businessDate = Carbon::parse($this->businessDate);

        return $nightAuditService->forBranch($branch)->postRoomCharges($businessDate);
    }
}
