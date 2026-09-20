<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Models\Room;
use App\Models\TabletSession;
use App\Services\TabletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TabletSessionController extends Controller
{
    public function pair(Request $request): RedirectResponse
    {
        $request->validate([
            'room_id' => 'required|exists:rooms,id',
            'reservation_id' => 'required|exists:reservations,id',
        ]);

        $room = Room::findOrFail($request->integer('room_id'));
        $reservation = Reservation::findOrFail($request->integer('reservation_id'));

        (new TabletService)->pair($room, $reservation);

        return $this->flashSuccess('Tablet paired to room.');
    }

    public function unpair(Request $request): RedirectResponse
    {
        $request->validate([
            'room_id' => 'required|exists:rooms,id',
        ]);

        $room = Room::findOrFail($request->integer('room_id'));

        (new TabletService)->unpair($room);

        return $this->flashSuccess('Tablet unpaired.');
    }

    public function wipe(Request $request): RedirectResponse
    {
        $request->validate([
            'tablet_session_id' => 'required|exists:tablet_sessions,id',
        ]);

        $session = TabletSession::findOrFail($request->integer('tablet_session_id'));
        (new TabletService)->wipeSession($session);

        return $this->flashSuccess('Tablet session wiped.');
    }
}
