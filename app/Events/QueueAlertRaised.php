<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class QueueAlertRaised implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $kind,
        public string $detail,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.system'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'queue.alert.raised';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'kind' => $this->kind,
            'detail' => $this->detail,
            'at' => now()->toISOString(),
        ];
    }
}
