<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\Room;
use App\Models\TabletSession;

class TabletService
{
    public function pair(Room $room, Reservation $reservation): TabletSession
    {
        return TabletSession::updateOrCreate(
            ['room_id' => $room->id, 'branch_id' => $room->branch_id],
            [
                'reservation_id' => $reservation->id,
                'confirmation_number' => $reservation->confirmation_number,
                'last_active_at' => now(),
                'wiped_at' => null,
            ]
        );
    }

    public function unpair(Room $room): void
    {
        $session = TabletSession::where('room_id', $room->id)
            ->whereNull('wiped_at')
            ->first();

        if ($session) {
            $session->wipe();
        }
    }

    public function wipeSession(TabletSession $session): void
    {
        $session->wipe();
    }

    public function getActiveSession(Room $room): ?TabletSession
    {
        return TabletSession::where('room_id', $room->id)
            ->whereNull('wiped_at')
            ->first();
    }
}
