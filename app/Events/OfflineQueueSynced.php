<?php

namespace App\Events;

use App\Models\Outlet;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OfflineQueueSynced implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Outlet $outlet,
        public int $posted,
        public int $skipped,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->outlet->branch_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'pos.offline_synced';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'branch_id' => $this->outlet->branch_id,
            'outlet_id' => $this->outlet->id,
            'posted' => $this->posted,
            'skipped' => $this->skipped,
            'at' => now()->toISOString(),
        ];
    }
}
