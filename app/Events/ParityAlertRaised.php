<?php

namespace App\Events;

use App\Models\Branch;
use App\Models\ChannelProviderModel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ParityAlertRaised implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    public function __construct(
        public Branch $branch,
        public ChannelProviderModel $provider,
        public string $stayDate,
        public array $entries,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->branch->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'channel.parity_alert';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'branch_id' => $this->branch->id,
            'provider' => $this->provider->provider,
            'stay_date' => $this->stayDate,
            'entries' => $this->entries,
            'at' => now()->toISOString(),
        ];
    }
}
