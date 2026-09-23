<?php

namespace App\Events;

use App\Models\MobileKey;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MobileKeyIssued implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public MobileKey $key,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->key->reservation->branch_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'mobile.key.issued';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'key_id' => $this->key->id,
            'reservation_id' => $this->key->reservation_id,
            'device_id' => $this->key->device_id,
        ];
    }
}
