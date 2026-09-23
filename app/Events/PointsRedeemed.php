<?php

namespace App\Events;

use App\Models\LoyaltyAccount;
use App\Models\LoyaltyLedger;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PointsRedeemed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public LoyaltyAccount $account,
        public LoyaltyLedger $entry,
        public int $points,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('crm.loyalty'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'loyalty.redeemed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'guest_id' => $this->account->guest_id,
            'points' => $this->points,
            'balance' => $this->account->points,
            'at' => now()->toISOString(),
        ];
    }
}
