<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BookingEngineController extends Controller
{
    public function index(): Response
    {
        $branches = Branch::active()
            ->with(['roomTypes' => fn ($q) => $q->where('is_active', true)])
            ->get();

        return Inertia::render('booking/Index', [
            'branches' => $branches,
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'check_in' => 'required|date|after_or_equal:today',
            'check_out' => 'required|date|after:check_in',
            'adults' => 'required|integer|min:1|max:10',
            'children' => 'required|integer|min:0|max:10',
        ]);

        $checkIn = $validated['check_in'];
        $checkOut = $validated['check_out'];
        $branchId = $validated['branch_id'];

        $roomTypes = RoomType::where('branch_id', $branchId)
            ->where('is_active', true)
            ->where('max_occupancy', '>=', $validated['adults'] + $validated['children'])
            ->withCount([
                'rooms as available_count' => function ($q) use ($checkIn, $checkOut) {
                    $q->where('is_active', true)
                        ->where('status', '!=', 'out_of_order')
                        ->whereDoesntHave('reservations', function ($rQ) use ($checkIn, $checkOut) {
                            $rQ->whereIn('status', ['confirmed', 'reserved', 'checked_in'])
                                ->where('check_in_date', '<', $checkOut)
                                ->where('check_out_date', '>', $checkIn);
                        });
                },
            ])
            ->get()
            ->filter(fn ($rt) => $rt->available_count > 0)
            ->values();

        $nights = Carbon::parse($checkIn)->diffInDays($checkOut);

        return response()->json([
            'room_types' => $roomTypes->map(fn ($rt) => [
                'id' => $rt->id,
                'name' => $rt->name,
                'code' => $rt->code,
                'description' => $rt->description,
                'base_rate' => $rt->base_rate,
                'max_occupancy' => $rt->max_occupancy,
                'bed_count' => $rt->bed_count,
                'bed_type' => $rt->bed_type,
                'amenities' => $rt->amenities,
                'available_count' => $rt->available_count,
                'total_rate' => $rt->base_rate * $nights,
            ]),
            'nights' => $nights,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'room_type_id' => 'required|exists:room_types,id',
            'check_in' => 'required|date|after_or_equal:today',
            'check_out' => 'required|date|after:check_in',
            'adults' => 'required|integer|min:1|max:10',
            'children' => 'required|integer|min:0|max:10',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:50',
            'special_requests' => 'nullable|array',
            'payment_method' => 'nullable|string',
        ]);

        $branch = Branch::findOrFail($validated['branch_id']);
        $roomType = RoomType::findOrFail($validated['room_type_id']);

        $nights = Carbon::parse($validated['check_in'])->diffInDays($validated['check_out']);
        $totalAmount = $roomType->base_rate * $nights;

        return DB::transaction(function () use ($validated, $branch, $roomType, $totalAmount) {
            $guest = Guest::firstOrCreate(
                ['email' => $validated['email']],
                [
                    'first_name' => $validated['first_name'],
                    'last_name' => $validated['last_name'],
                    'phone' => $validated['phone'] ?? null,
                    'currency_code' => $branch->currency_code,
                    'preferred_currency' => $branch->currency_code,
                ]
            );

            $reservation = Reservation::create([
                'branch_id' => $branch->id,
                'room_type_id' => $roomType->id,
                'guest_id' => $guest->id,
                'status' => 'confirmed',
                'source' => 'direct',
                'guest_name' => $guest->full_name,
                'guest_email' => $guest->email,
                'guest_phone' => $guest->phone,
                'adults' => $validated['adults'],
                'children' => $validated['children'],
                'check_in_date' => $validated['check_in'],
                'check_out_date' => $validated['check_out'],
                'room_rate' => $roomType->base_rate,
                'total_amount' => $totalAmount,
                'amount_paid' => 0,
                'payment_status' => 'pending',
                'special_requests' => $validated['special_requests'] ?? null,
            ]);

            return response()->json([
                'reservation' => $reservation->load(['branch', 'roomType', 'guest']),
                'message' => 'Reservation created successfully. Confirmation: '.$reservation->confirmation_number,
            ], 201);
        });
    }

    public function availability(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'check_in' => 'required|date',
            'check_out' => 'required|date|after:check_in',
        ]);

        $checkIn = $validated['check_in'];
        $checkOut = $validated['check_out'];

        $bookedRoomIds = Reservation::where('branch_id', $validated['branch_id'])
            ->whereIn('status', ['confirmed', 'reserved', 'checked_in'])
            ->where('check_in_date', '<', $checkOut)
            ->where('check_out_date', '>', $checkIn)
            ->whereNotNull('room_id')
            ->pluck('room_id');

        $availableRooms = Room::where('branch_id', $validated['branch_id'])
            ->where('is_active', true)
            ->where('status', '!=', 'out_of_order')
            ->whereNotIn('id', $bookedRoomIds)
            ->with('roomType')
            ->get();

        return response()->json([
            'available_rooms' => $availableRooms,
            'count' => $availableRooms->count(),
        ]);
    }
}
