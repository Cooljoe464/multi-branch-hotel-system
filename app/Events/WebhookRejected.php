<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WebhookRejected implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $driver,
        public string $reason,
        public ?int $branchId = null,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.system'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'webhook.rejected';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'driver' => $this->driver,
            'reason' => $this->reason,
            'branch_id' => $this->branchId,
            'at' => now()->toISOString(),
        ];
    }
}
