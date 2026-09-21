<?php

namespace App\Events;

use App\Models\Reservation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NoShowPenaltyPosted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Reservation $reservation,
        public int $feeMinor,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->reservation->branch_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'noshow.penalty_posted';
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
            'fee_minor' => $this->feeMinor,
            'at' => now()->toISOString(),
        ];
    }
}
