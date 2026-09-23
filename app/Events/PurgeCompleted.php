<?php

namespace App\Events;

use App\Models\DsarRequest;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PurgeCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $receipt  Counts only, never PII.
     */
    public function __construct(
        public DsarRequest $request,
        public array $receipt,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('privacy.dsar'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'dsar.purge_completed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'request_id' => $this->request->id,
            'receipt' => $this->receipt,
            'at' => now()->toISOString(),
        ];
    }
}
