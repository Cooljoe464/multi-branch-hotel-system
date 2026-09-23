<?php

namespace App\Events;

use App\Models\Reservation;
use App\Models\ReservationMove;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GuestMoved implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Reservation $reservation,
        public ReservationMove $move,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->reservation->branch_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'guest.moved';
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
            'from_room_id' => $this->move->from_room_id,
            'to_room_id' => $this->move->to_room_id,
            'at' => now()->toISOString(),
        ];
    }
}
