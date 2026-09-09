<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Notifications\CheckInNotification;
use App\Notifications\CheckoutNotification;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReservationController extends Controller
{
    public function index(Request $request): Response
    {
        $branchId = $request->user()->branch_id;

        $reservations = Reservation::forBranch($branchId)
            ->with(['room', 'roomType', 'guest'])
            ->when($request->status, fn ($q, $status) => $q->forStatus($status))
            ->when($request->date, fn ($q, $date) => $q->forDate($date))
            ->when($request->search, function ($q, $search) {
                $q->where(function ($query) use ($search) {
                    $query->where('guest_name', 'ilike', "%{$search}%")
                        ->orWhere('confirmation_number', 'ilike', "%{$search}%")
                        ->orWhere('guest_email', 'ilike', "%{$search}%");
                });
            })
            ->orderBy($request->sort ?? 'check_in_date', $request->direction ?? 'asc')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('reservations/Index', [
            'reservations' => $reservations,
            'filters' => $request->only(['status', 'date', 'sort', 'direction', 'search']),
        ]);
    }

    public function create(Request $request): Response
    {
        $branchId = $request->user()->branch_id;

        $roomTypes = RoomType::forBranch($branchId)->active()->get();
        $availableRooms = Room::forBranch($branchId)
            ->available()
            ->with('roomType')
            ->get();

        $branches = Branch::active()->get();

        return Inertia::render('reservations/Create', [
            'roomTypes' => $roomTypes,
            'availableRooms' => $availableRooms,
            'branches' => $branches,
            'prefilledDate' => $request->date,
            'prefilledBranch' => $request->branch_id,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'room_type_id' => 'required|exists:room_types,id',
            'room_id' => 'nullable|exists:rooms,id',
            'branch_id' => 'sometimes|exists:branches,id',
            'guest_id' => 'nullable|exists:guests,id',
            'guest_name' => 'required|string|max:255',
            'guest_email' => 'nullable|email|max:255',
            'guest_phone' => 'nullable|string|max:50',
            'guest_notes' => 'nullable|string',
            'adults' => 'required|integer|min:1|max:10',
            'children' => 'required|integer|min:0|max:10',
            'check_in_date' => 'required|date|after_or_equal:today',
            'check_out_date' => 'required|date|after:check_in_date',
            'special_requests' => 'nullable|array',
            'is_group_booking' => 'boolean',
            'group_id' => 'nullable|string|max:50',
        ]);

        $branchId = $validated['branch_id'] ?? $request->user()->branch_id;
        $roomType = RoomType::findOrFail($validated['room_type_id']);

        $validated['branch_id'] = $branchId;
        $validated['room_rate'] = $roomType->base_rate;
        $validated['status'] = 'confirmed';
        $validated['source'] = 'direct';
        $validated['payment_status'] = 'pending';

        $nights = Carbon::parse($validated['check_in_date'])
            ->diffInDays($validated['check_out_date']);
        $validated['total_amount'] = $roomType->base_rate * $nights;

        // Link to guest profile if email provided
        if (! empty($validated['guest_email']) && empty($validated['guest_id'])) {
            $guest = Guest::firstOrCreate(
                ['email' => $validated['guest_email']],
                [
                    'first_name' => explode(' ', $validated['guest_name'])[0] ?? $validated['guest_name'],
                    'last_name' => implode(' ', array_slice(explode(' ', $validated['guest_name']), 1)) ?: '',
                    'phone' => $validated['guest_phone'] ?? null,
                ]
            );
            $validated['guest_id'] = $guest->id;
        }

        $reservation = Reservation::create($validated);

        if (! empty($validated['room_id'])) {
            $room = Room::find($validated['room_id']);
            if ($room && $room->status === 'available') {
                $room->update(['status' => 'reserved']);
            }
        }

        return redirect()->route('reservations.show', $reservation)
            ->with('success', 'Reservation '.$reservation->confirmation_number.' created.');
    }

    public function show(Reservation $reservation): Response
    {
        $reservation->load(['room', 'roomType', 'branch', 'guest']);

        return Inertia::render('reservations/Show', [
            'reservation' => $reservation,
        ]);
    }

    public function edit(Reservation $reservation): Response
    {
        $branchId = $reservation->branch_id;

        $roomTypes = RoomType::forBranch($branchId)->active()->get();
        $availableRooms = Room::forBranch($branchId)
            ->available()
            ->orWhere('id', $reservation->room_id)
            ->with('roomType')
            ->get();

        $branches = Branch::active()->get();

        return Inertia::render('reservations/Edit', [
            'reservation' => $reservation->load(['room', 'roomType', 'guest']),
            'roomTypes' => $roomTypes,
            'availableRooms' => $availableRooms,
            'branches' => $branches,
        ]);
    }

    public function update(Request $request, Reservation $reservation)
    {
        $validated = $request->validate([
            'room_type_id' => 'sometimes|exists:room_types,id',
            'room_id' => 'nullable|exists:rooms,id',
            'branch_id' => 'sometimes|exists:branches,id',
            'guest_name' => 'sometimes|string|max:255',
            'guest_email' => 'nullable|email|max:255',
            'guest_phone' => 'nullable|string|max:50',
            'guest_notes' => 'nullable|string',
            'adults' => 'sometimes|integer|min:1|max:10',
            'children' => 'sometimes|integer|min:0|max:10',
            'check_in_date' => 'sometimes|date',
            'check_out_date' => 'sometimes|date|after:check_in_date',
            'special_requests' => 'nullable|array',
        ]);

        $reservation->update($validated);

        return back()->with('success', 'Reservation updated.');
    }

    public function checkIn(Request $request, Reservation $reservation)
    {
        if ($reservation->status !== 'confirmed' && $reservation->status !== 'reserved') {
            return back()->withErrors(['status' => 'This reservation cannot be checked in.']);
        }

        $validated = $request->validate([
            'room_id' => 'required|exists:rooms,id',
        ]);

        $room = Room::find($validated['room_id']);

        if ($room->status !== 'available') {
            return back()->withErrors(['room_id' => 'This room is not available.']);
        }

        $oldRoomId = $reservation->room_id;

        $reservation->update([
            'room_id' => $room->id,
            'status' => 'checked_in',
            'actual_check_in_at' => now(),
        ]);

        $room->update(['status' => 'occupied']);

        if ($oldRoomId && $oldRoomId !== $room->id) {
            $oldRoom = Room::find($oldRoomId);
            if ($oldRoom && $oldRoom->status === 'reserved') {
                $oldRoom->update(['status' => 'available']);
            }
        }

        // Update guest lifetime stats
        if ($reservation->guest) {
            $reservation->guest->incrementStay(
                $reservation->nights,
                $reservation->total_amount
            );
        }

        // Send check-in notification
        if ($reservation->guest_email && $reservation->guest) {
            $reservation->guest->notify(new CheckInNotification($reservation));
        }

        return back()->with('success', 'Guest checked in to room '.$room->number.'.');
    }

    public function checkOut(Reservation $reservation)
    {
        if ($reservation->status !== 'checked_in') {
            return back()->withErrors(['status' => 'This reservation is not checked in.']);
        }

        $reservation->update([
            'status' => 'checked_out',
            'actual_check_out_at' => now(),
        ]);

        if ($reservation->room) {
            $reservation->room->update(['status' => 'dirty']);
        }

        // Send checkout notification
        if ($reservation->guest_email && $reservation->guest) {
            $reservation->guest->notify(new CheckoutNotification($reservation));
        }

        return back()->with('success', 'Guest checked out from room '.$reservation->room?->number.'.');
    }

    public function cancel(Reservation $reservation)
    {
        if (in_array($reservation->status, ['checked_out', 'cancelled'])) {
            return back()->withErrors(['status' => 'This reservation cannot be cancelled.']);
        }

        $reservation->update(['status' => 'cancelled']);

        if ($reservation->room && $reservation->room->status === 'reserved') {
            $reservation->room->update(['status' => 'available']);
        }

        return back()->with('success', 'Reservation cancelled.');
    }

    public function destroy(Reservation $reservation)
    {
        if (in_array($reservation->status, ['checked_in'])) {
            return back()->withErrors(['status' => 'Cannot delete an active reservation.']);
        }

        $reservation->delete();

        return redirect()->route('reservations.index')
            ->with('success', 'Reservation deleted.');
    }

    /**
     * Search availability across all branches for cross-branch booking.
     */
    public function crossBranchSearch(Request $request): Response
    {
        $validated = $request->validate([
            'check_in' => 'required|date|after_or_equal:today',
            'check_out' => 'required|date|after:check_in',
            'adults' => 'required|integer|min:1|max:10',
        ]);

        $checkIn = $validated['check_in'];
        $checkOut = $validated['check_out'];
        $adults = $validated['adults'];

        $branches = Branch::active()->with(['roomTypes' => fn ($q) => $q->where('is_active', true)])->get();

        $results = $branches->map(function ($branch) use ($checkIn, $checkOut, $adults) {
            $roomTypes = $branch->roomTypes->filter(fn ($rt) => $rt->max_occupancy >= $adults);

            $availableData = $roomTypes->map(function ($rt) use ($branch, $checkIn, $checkOut) {
                $bookedCount = Reservation::where('branch_id', $branch->id)
                    ->where('room_type_id', $rt->id)
                    ->whereIn('status', ['confirmed', 'reserved', 'checked_in'])
                    ->where('check_in_date', '<', $checkOut)
                    ->where('check_out_date', '>', $checkIn)
                    ->count();

                $totalRooms = $rt->rooms()->where('is_active', true)->count();
                $available = max(0, $totalRooms - $bookedCount);

                return [
                    'room_type_id' => $rt->id,
                    'room_type_name' => $rt->name,
                    'base_rate' => $rt->base_rate,
                    'available_count' => $available,
                ];
            })->filter(fn ($data) => $data['available_count'] > 0);

            return [
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                'branch_city' => $branch->city,
                'room_types' => $availableData->values(),
            ];
        })->filter(fn ($branch) => $branch['room_types']->isNotEmpty());

        return Inertia::render('reservations/CrossBranchSearch', [
            'results' => $results->values(),
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'adults' => $adults,
        ]);
    }
}
