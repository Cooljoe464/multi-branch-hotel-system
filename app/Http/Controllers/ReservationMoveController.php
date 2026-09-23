<?php

namespace App\Http\Controllers;

use App\Exceptions\AvailabilityException;
use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Reservation;
use App\Models\Room;
use App\Services\RoomMoveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReservationMoveController extends Controller
{
    use EnsuresBranchAccess;

    public function store(Request $request, Reservation $reservation): RedirectResponse
    {
        $reservation->load(['branch']);

        $this->ensureBranchAccess($reservation->branch);

        $request->validate([
            'room_id' => 'required|exists:rooms,id',
            'reason' => 'nullable|string|max:255',
        ]);

        $room = Room::findOrFail($request->integer('room_id'));

        $key = $request->header('X-Idempotency-Key');
        $key = is_string($key) && trim($key) !== '' ? trim($key) : null;

        $reason = $request->string('reason')->value();

        try {
            $move = (new RoomMoveService)->move(
                $reservation,
                $room,
                $request->user(),
                $key,
                ['reason' => $reason !== '' ? $reason : null],
            );
        } catch (AvailabilityException $e) {
            if ($e->availabilityCode === 'KEY_REISSUE_FAILED') {
                abort(502, $e->getMessage());
            }

            return back()->withErrors(['room_id' => $e->getMessage()]);
        }

        return $this->flashSuccess("Guest moved to room {$move->toRoom->number}.");
    }
}
