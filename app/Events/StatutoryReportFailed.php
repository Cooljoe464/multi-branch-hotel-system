<?php

namespace App\Events;

use App\Models\StatutoryReport;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StatutoryReportFailed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public StatutoryReport $report,
        public string $error,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->report->branch_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'statutory.report_failed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'branch_id' => $this->report->branch_id,
            'report_id' => $this->report->id,
            'error' => $this->error,
            'at' => now()->toISOString(),
        ];
    }
}
