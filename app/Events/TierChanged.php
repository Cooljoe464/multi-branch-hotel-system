<?php

namespace App\Events;

use App\Models\LoyaltyAccount;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TierChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public LoyaltyAccount $account,
        public string $tier,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('crm.loyalty'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'loyalty.tier_changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'guest_id' => $this->account->guest_id,
            'tier' => $this->tier,
            'at' => now()->toISOString(),
        ];
    }
}
