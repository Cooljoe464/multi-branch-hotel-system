<?php

namespace App\Events;

use App\Models\CommissionAccrual;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CommissionAccrued implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public CommissionAccrual $accrual,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->accrual->branch_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'commission.accrued';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'branch_id' => $this->accrual->branch_id,
            'accrual_id' => $this->accrual->id,
            'source' => $this->accrual->source,
            'amount_minor' => $this->accrual->amount_minor,
            'at' => now()->toISOString(),
        ];
    }
}
