<?php

namespace App\Events;

use App\Models\DsarRequest;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DsarFulfilled implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public DsarRequest $request,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('privacy.dsar'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'dsar.fulfilled';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'request_id' => $this->request->id,
            'kind' => $this->request->kind,
            'status' => $this->request->status,
            'at' => now()->toISOString(),
        ];
    }
}
