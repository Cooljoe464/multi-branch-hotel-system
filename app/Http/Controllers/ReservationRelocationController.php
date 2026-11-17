<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Reservation;
use App\Models\Room;
use App\Services\RelocationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ReservationRelocationController extends Controller
{
    public function store(Request $request, Reservation $reservation, RelocationService $relocations): RedirectResponse
    {
        $request->validate([
            'destination_branch_id' => 'required|integer|exists:branches,id',
            'destination_room_id' => 'required|integer|exists:rooms,id',
            'compensation_minor' => 'required|integer|min:1',
            'reason' => 'required|string|max:500',
        ]);

        $branch = Branch::query()->whereKey($request->integer('destination_branch_id'))->where('is_active', true)->firstOrFail();
        $room = Room::query()->whereKey($request->integer('destination_room_id'))->firstOrFail();
        $relocations->create($reservation, $branch, $room, $request->integer('compensation_minor'), $request->string('reason')->value(), $request->header('X-Idempotency-Key', (string) Str::uuid()), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Relocation recorded with compensation.']);
    }
}
