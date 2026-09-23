<?php

namespace App\Events;

use App\Models\StatutoryReport;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StatutoryReportReady implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public StatutoryReport $report,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->report->branch_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'statutory.report_ready';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'branch_id' => $this->report->branch_id,
            'report_id' => $this->report->id,
            'kind' => $this->report->kind,
            'at' => now()->toISOString(),
        ];
    }
}
