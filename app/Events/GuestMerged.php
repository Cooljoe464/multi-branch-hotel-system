<?php

namespace App\Events;

use App\Models\Guest;
use App\Models\GuestMergeLink;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GuestMerged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Guest $survivor,
        public GuestMergeLink $link,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('crm.merges'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'guest.merged';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'survivor_id' => $this->survivor->id,
            'retired_id' => $this->link->retired_guest_id,
            'at' => now()->toISOString(),
        ];
    }
}
