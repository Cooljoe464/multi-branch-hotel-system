<?php

namespace App\Events;

use App\Models\CommissionPayout;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PayoutCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public CommissionPayout $payout,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->payout->branch_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'commission.payout_completed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'branch_id' => $this->payout->branch_id,
            'payout_id' => $this->payout->id,
            'source' => $this->payout->source,
            'amount_minor' => $this->payout->amount_minor,
            'at' => now()->toISOString(),
        ];
    }
}
