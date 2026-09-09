<?php

namespace App\Events;

use App\Models\Room;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RoomStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Room $room,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.'.$this->room->branch_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'room.status.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->room->id,
            'number' => $this->room->number,
            'status' => $this->room->status,
            'floor' => $this->room->floor,
            'wing' => $this->room->wing,
            'room_type' => [
                'id' => $this->room->roomType->id,
                'name' => $this->room->roomType->name,
                'code' => $this->room->roomType->code,
            ],
            'updated_at' => $this->room->updated_at->toISOString(),
        ];
    }
}
