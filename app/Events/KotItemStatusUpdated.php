<?php

namespace App\Events;

use App\Models\KotItem;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class KotItemStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public KotItem $kotItem,
        public string $previousStatus,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('branch.'.$this->kotItem->branch_id.'.kds');
    }

    public function broadcastAs(): string
    {
        return 'kot.updated';
    }

    /**
     * @return array{id: int, item_name: string, quantity: int, status: string, previous_status: string, priority: string, outlet: string}
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->kotItem->id,
            'item_name' => $this->kotItem->item_name,
            'quantity' => $this->kotItem->quantity,
            'status' => $this->kotItem->status,
            'previous_status' => $this->previousStatus,
            'priority' => $this->kotItem->priority,
            'outlet' => $this->kotItem->outlet,
        ];
    }
}
