<?php

namespace App\Events;

use App\Models\GroupBlock;
use App\Models\Reservation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BlockPickupChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public GroupBlock $block,
        public Reservation $reservation,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->block->branch_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'block.pickup_changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'branch_id' => $this->block->branch_id,
            'block_id' => $this->block->id,
            'block_code' => $this->block->code,
            'reservation_id' => $this->reservation->id,
            'at' => now()->toISOString(),
        ];
    }
}
