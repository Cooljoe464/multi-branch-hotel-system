<?php

namespace App\Events;

use App\Models\Branch;
use App\Models\ChannelProviderModel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChannelReconciled implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Branch $branch,
        public ChannelProviderModel $provider,
        public int $drifted,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->branch->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'channel.reconciled';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'branch_id' => $this->branch->id,
            'provider' => $this->provider->provider,
            'drifted' => $this->drifted,
            'at' => now()->toISOString(),
        ];
    }
}
