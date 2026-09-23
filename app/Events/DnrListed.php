<?php

namespace App\Events;

use App\Models\DoNotRent;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DnrListed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public DoNotRent $entry,
    ) {}

    public function broadcastOn(): array
    {
        // Reason stays server-side: the channel carries ids only, and
        // only where a branch scope exists.
        $channels = [];

        if ($this->entry->branch_id !== null) {
            $channels[] = new PrivateChannel('branch.'.$this->entry->branch_id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'dnr.listed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'branch_id' => $this->entry->branch_id,
            'entry_id' => $this->entry->id,
            'at' => now()->toISOString(),
        ];
    }
}
