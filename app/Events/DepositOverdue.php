<?php

namespace App\Events;

use App\Models\Reservation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DepositOverdue implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Reservation $reservation,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->reservation->branch_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'deposit.overdue';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'branch_id' => $this->reservation->branch_id,
            'reservation_id' => $this->reservation->id,
            'confirmation_number' => $this->reservation->confirmation_number,
            'deposit_due_minor' => $this->reservation->deposit_due_minor,
            'deposit_paid_minor' => $this->reservation->deposit_paid_minor,
            'at' => now()->toISOString(),
        ];
    }
}
