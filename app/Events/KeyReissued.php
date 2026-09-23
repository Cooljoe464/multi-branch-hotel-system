<?php

namespace App\Events;

use App\Models\Reservation;
use App\Models\Room;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class KeyReissued implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Reservation $reservation,
        public Room $room,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->reservation->branch_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'key.reissued';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'branch_id' => $this->reservation->branch_id,
            'reservation_id' => $this->reservation->id,
            'room_id' => $this->room->id,
            'room_number' => $this->room->number,
            'at' => now()->toISOString(),
        ];
    }
}
