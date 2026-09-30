<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Branch;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\TabletSession;
use App\Services\TabletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TabletSessionController extends Controller
{
    use EnsuresBranchAccess;

    public function index(Branch $branch): Response
    {
        $this->ensureBranchAccess($branch);

        $sessions = TabletSession::where('branch_id', $branch->id)
            ->with(['room', 'reservation'])
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (TabletSession $s) => [
                'id' => $s->id,
                'room' => $s->room->number,
                'reservation' => $s->reservation->confirmation_number,
                'device_id' => $s->device_id,
                'last_active_at' => $s->last_active_at?->toIso8601String(),
                'wiped_at' => $s->wiped_at?->toIso8601String(),
            ])
            ->all();

        return Inertia::render('rooms/Tablets', [
            'branch' => $branch->only(['id', 'name']),
            'sessions' => $sessions,
        ]);
    }

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
