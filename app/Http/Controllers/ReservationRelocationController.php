<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Relocation;
use App\Models\Reservation;
use App\Models\Room;
use App\Services\RelocationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ReservationRelocationController extends Controller
{
    use Concerns\EnsuresBranchAccess;

    /**
     * Overbooked arrivals that still need a relocation, the relocations
     * already recorded, and same-tenant properties with rooms to send guests to.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $branch = $user->currentBranch;
        abort_unless($branch instanceof Branch, 404);

        $needing = Reservation::query()
            ->where('branch_id', $branch->id)
            ->where('overbooked', true)
            ->whereIn('status', ['pending', 'confirmed', 'reserved'])
            ->orderBy('check_in_date')
            ->get()
            ->filter(fn (Reservation $reservation): bool => empty($reservation->metadata['relocation_id'] ?? null))
            ->map(fn (Reservation $reservation): array => [
                'id' => $reservation->id,
                'confirmation_number' => $reservation->confirmation_number,
                'guest_name' => $reservation->guest_name,
                'check_in_date' => $reservation->check_in_date->toDateString(),
                'guests' => $reservation->adults + $reservation->children,
            ])
            ->values();

        $relocations = Relocation::query()
            ->where('source_branch_id', $branch->id)
            ->with(['reservation:id,confirmation_number,guest_name', 'destinationBranch:id,name'])
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (Relocation $relocation): array => [
                'id' => $relocation->id,
                'confirmation_number' => $relocation->reservation->confirmation_number,
                'guest_name' => $relocation->reservation->guest_name,
                'destination' => $relocation->destinationBranch->name,
                'status' => $relocation->status,
                'compensation_minor' => $relocation->compensation_minor,
                'currency_code' => $relocation->currency_code,
                'reason' => $relocation->reason,
            ])
            ->values();

        $destinations = Branch::query()
            ->where('tenant_id', $branch->tenant_id)
            ->where('is_active', true)
            ->whereKeyNot($branch->id)
            ->with(['rooms' => fn ($query) => $query->where('status', 'available')->where('is_active', true)->orderBy('number')->limit(100)])
            ->get()
            ->map(fn (Branch $destination): array => [
                'id' => $destination->id,
                'name' => $destination->name,
                'rooms' => $destination->rooms->map(fn (Room $room): array => [
                    'id' => $room->id,
                    'number' => $room->number,
                ])->values(),
            ])
            ->values();

        return Inertia::render('reservations/Relocations', [
            'branch' => $branch->only(['id', 'name', 'currency_code']),
            'needing' => $needing,
            'relocations' => $relocations,
            'destinations' => $destinations,
        ]);
    }

    public function store(Request $request, Reservation $reservation, RelocationService $relocations): RedirectResponse
    {
        $this->ensureBranchAccess($reservation->branch);

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
