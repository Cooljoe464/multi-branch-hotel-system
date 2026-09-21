<?php

namespace App\Events;

use App\Models\CashierShift;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ShiftClosed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public CashierShift $shift,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->shift->branch_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'shift.closed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'shift_id' => $this->shift->id,
            'branch_id' => $this->shift->branch_id,
            'user_id' => $this->shift->user_id,
            'expected_cash_minor' => $this->shift->expected_cash_minor,
            'counted_cash_minor' => $this->shift->counted_cash_minor,
            'variance_minor' => $this->shift->variance_minor,
            'at' => now()->toISOString(),
        ];
    }
}
