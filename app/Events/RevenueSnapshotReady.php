<?php

namespace App\Events;

use App\Models\Branch;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RevenueSnapshotReady implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Branch $branch,
        public string $snapshotDate,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->branch->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'revenue.snapshot_ready';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'branch_id' => $this->branch->id,
            'snapshot_date' => $this->snapshotDate,
            'at' => now()->toISOString(),
        ];
    }
}
