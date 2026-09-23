<?php

namespace App\Events;

use App\Models\GroupBlock;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BlockCutoffReleased implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public GroupBlock $block,
        public string $reason,
        public int $releasedNights,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->block->branch_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'block.cutoff_released';
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
            'reason' => $this->reason,
            'released_nights' => $this->releasedNights,
            'at' => now()->toISOString(),
        ];
    }
}
