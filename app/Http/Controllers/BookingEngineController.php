<?php

namespace App\Http\Controllers;

use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\GuestDedupService;
use App\Services\PricingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BookingEngineController extends Controller
{
    public function index(): Response
    {
        $branches = Branch::active()
            ->with('roomTypes')
            ->get()
            ->each(function (Branch $branch) {
                $branch->setRelation('roomTypes', $branch->roomTypes->where('is_active', true));
            });

        return Inertia::render('booking/Index', [
            'branches' => $branches,
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'check_in' => 'required|date|after_or_equal:today',
            'check_out' => 'required|date|after:check_in',
            'adults' => 'required|integer|min:1|max:10',
            'children' => 'required|integer|min:0|max:10',
        ]);

        $checkIn = $request->string('check_in')->value();
        $checkOut = $request->string('check_out')->value();
        $branchId = $request->integer('branch_id');
        $adults = $request->integer('adults');
        $children = $request->integer('children');

        $branch = Branch::findOrFail($branchId);
        $pricingService = (new PricingService)->forBranch($branch);

        $roomTypes = $pricingService->getAvailableRoomTypesForSearch(
            $branchId,
            $checkIn,
            $checkOut,
            $adults + $children
        );

        $nights = Carbon::parse($checkIn)->diffInDays($checkOut);

        return response()->json([
            'room_types' => $roomTypes->values(),
            'nights' => $nights,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $request->validate([
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

        $branchId = $request->integer('branch_id');
        $roomTypeId = $request->integer('room_type_id');
        $checkIn = $request->string('check_in')->value();
        $checkOut = $request->string('check_out')->value();
        $adults = $request->integer('adults');
        $children = $request->integer('children');
        $email = $request->string('email')->value();
        $firstName = $request->string('first_name')->value();
        $lastName = $request->string('last_name')->value();
        $phone = $request->string('phone')->value();
        $specialRequests = $request->input('special_requests');

        $branch = Branch::findOrFail($branchId);
        $roomType = RoomType::findOrFail($roomTypeId);

        $pricingService = (new PricingService)->forBranch($branch);
        $pricing = $pricingService->calculateTotal(
            $roomType,
            Carbon::parse($checkIn),
            Carbon::parse($checkOut)
        );

        if ($pricing['cta_violated']) {
            return back()->withErrors(['check_in' => 'Check-in is closed for this date.']);
        }

        if (! $pricing['mlos_met']) {
            return back()->withErrors(['check_out' => 'Minimum length of stay is '.$pricing['mlos'].' nights.']);
        }

        return DB::transaction(function () use ($branch, $roomType, $pricing, $checkIn, $checkOut, $adults, $children, $email, $firstName, $lastName, $phone, $specialRequests) {
            // Silent reject: no reason leaks to the public engine.
            try {
                (new GuestDedupService)->assertRentable($branch, null, $email);
            } catch (AvailabilityException) {
                return back()->withErrors(['email' => 'Unable to complete this booking. Please contact the property directly.']);
            }

            $hasOverlap = Reservation::where('branch_id', $branch->id)
                ->where('room_type_id', $roomType->id)
                ->whereIn('status', ['confirmed', 'reserved', 'checked_in'])
                ->where('check_in_date', '<', $checkOut)
                ->where('check_out_date', '>', $checkIn)
                ->count();

            $totalRooms = $roomType->rooms()->where('is_active', true)->count();
            if ($hasOverlap >= $totalRooms) {
                return back()->withErrors(['room_type_id' => 'No rooms of this type are available for the selected dates.']);
            }

            $guest = Guest::firstOrCreate(
                ['email' => $email],
                [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'phone' => $phone,
                    'currency_code' => $branch->currency_code,
                    'preferred_currency' => $branch->currency_code,
                ]
            );

            $reservation = Reservation::create([
                'branch_id' => $branch->id,
                'currency_code' => $branch->currency_code,
                'room_type_id' => $roomType->id,
                'guest_id' => $guest->id,
                'status' => 'confirmed',
                'source' => 'direct',
                'guest_name' => $guest->full_name,
                'guest_email' => $guest->email,
                'guest_phone' => $guest->phone,
                'adults' => $adults,
                'children' => $children,
                'check_in_date' => $checkIn,
                'check_out_date' => $checkOut,
                'room_rate' => $pricing['per_night'][0]['rate'] ?? $roomType->base_rate,
                'total_amount' => $pricing['total'],
                'amount_paid' => 0,
                'payment_status' => 'pending',
                'special_requests' => $specialRequests,
            ]);

            return response()->json([
                'reservation' => $reservation->load(['branch', 'roomType', 'guest']),
                'message' => 'Reservation created successfully. Confirmation: '.$reservation->confirmation_number,
            ], 201);
        });
    }

    public function availability(Request $request): JsonResponse
    {
        $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'check_in' => 'required|date',
            'check_out' => 'required|date|after:check_in',
        ]);

        $checkIn = $request->string('check_in')->value();
        $checkOut = $request->string('check_out')->value();
        $branchId = $request->integer('branch_id');

        $bookedRoomIds = Reservation::where('branch_id', $branchId)
            ->whereIn('status', ['confirmed', 'reserved', 'checked_in'])
            ->where('check_in_date', '<', $checkOut)
            ->where('check_out_date', '>', $checkIn)
            ->whereNotNull('room_id')
            ->pluck('room_id');

        $availableRooms = Room::where('branch_id', $branchId)
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
