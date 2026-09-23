<?php

namespace App\Http\Controllers;

use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Models\CorporateAccount;
use App\Models\Guest;
use App\Models\PostStaySurvey;
use App\Models\PromoCode;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\TabletSession;
use App\Notifications\CheckInNotification;
use App\Notifications\CheckoutNotification;
use App\Services\AvailabilityService;
use App\Services\CommissionService;
use App\Services\DoorLock\DoorLockService;
use App\Services\GuaranteeService;
use App\Services\LoyaltyService;
use App\Services\PricingService;
use App\Services\RateEngine;
use App\Services\TabletService;
use App\Services\UpsellService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class ReservationController extends Controller
{
    use Concerns\EnsuresBranchAccess;

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $branchId = (int) $user->branch_id;

        $reservations = Reservation::forBranch($branchId)
            ->with(['room', 'roomType', 'guest'])
            ->when($request->filled('status') && $request->string('status')->value() !== 'all', fn ($q) => $q->forStatus($request->string('status')->value()))
            ->when($request->filled('date'), fn ($q) => $q->forDate($request->string('date')->value()))
            ->when($request->filled('search'), function ($q) use ($request) {
                $searchStr = $request->string('search')->value();
                $q->where(function ($query) use ($searchStr) {
                    $query->where('guest_name', 'ilike', "%{$searchStr}%")
                        ->orWhere('confirmation_number', 'ilike', "%{$searchStr}%")
                        ->orWhere('guest_email', 'ilike', "%{$searchStr}%");
                });
            })
            ->orderBy(
                $request->string('sort', 'created_at')->value(),
                in_array($request->string('direction')->value(), ['asc', 'desc']) ? $request->string('direction')->value() : 'desc'
            )
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('reservations/Index', [
            'reservations' => $reservations,
            'filters' => $request->only(['status', 'date', 'sort', 'direction', 'search']),
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $branchId = (int) $user->branch_id;

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

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
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
            'rate_plan_id' => 'nullable|exists:rate_plans,id',
            'promo_code' => 'nullable|string|max:50',
            'corporate_account_id' => 'nullable|exists:corporate_accounts,id',
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $branchId = $request->filled('branch_id') ? $request->integer('branch_id') : (int) $user->branch_id;
        $branch = Branch::findOrFail($branchId);

        $this->ensureBranchAccess($branch);

        $roomType = RoomType::findOrFail($request->integer('room_type_id'));

        if ($roomType->branch_id !== $branch->id) {
            abort(403, 'The selected room type does not belong to this property.');
        }

        $checkInDate = $request->string('check_in_date')->value();
        $checkOutDate = $request->string('check_out_date')->value();
        $guestName = $request->string('guest_name')->value();
        $guestEmail = $request->string('guest_email')->value();
        $guestPhone = $request->string('guest_phone')->value();
        $guestId = $request->filled('guest_id') ? $request->integer('guest_id') : null;
        $roomId = $request->filled('room_id') ? $request->integer('room_id') : null;
        $adults = $request->integer('adults');
        $children = $request->integer('children');
        $specialRequests = $request->input('special_requests');
        $isGroupBooking = $request->boolean('is_group_booking');
        $groupId = $request->string('group_id')->value();

        if ($roomId !== null) {
            $selectedRoom = Room::find($roomId);

            if (! $selectedRoom || $selectedRoom->branch_id !== $branch->id) {
                abort(403, 'The selected room does not belong to this property.');
            }
        }

        $pricingService = (new PricingService)->forBranch($branch);
        $pricing = $pricingService->calculateTotal(
            $roomType,
            Carbon::parse($checkInDate),
            Carbon::parse($checkOutDate)
        );

        if ($pricing['cta_violated']) {
            return back()->withErrors(['check_in_date' => 'Check-in is closed for this date.']);
        }

        if (! $pricing['mlos_met']) {
            return back()->withErrors(['check_out_date' => 'Minimum length of stay is '.$pricing['mlos'].' nights.']);
        }

        if ($guestEmail !== '' && $guestId === null) {
            $nameParts = explode(' ', $guestName);
            $guest = Guest::firstOrCreate(
                ['email' => $guestEmail],
                [
                    'first_name' => $nameParts[0],
                    'last_name' => implode(' ', array_slice($nameParts, 1)) ?: '',
                    'phone' => $guestPhone !== '' ? $guestPhone : null,
                ]
            );
            $guestId = $guest->id;
        }

        $idempotencyKey = $request->header('X-Idempotency-Key');
        $overbookReason = $request->string('overbook_reason')->value();
        $availability = new AvailabilityService;

        $ratePlan = $availability->defaultPlan($branch);

        if ($request->filled('rate_plan_id')) {
            $requested = RatePlan::forBranch($branch->id)->active()->find($request->integer('rate_plan_id'));

            if (! $requested) {
                return back()->withErrors(['rate_plan_id' => 'The selected rate plan is not available for this property.']);
            }

            $ratePlan = $requested;
        }

        $corporate = null;

        if ($request->filled('corporate_account_id')) {
            $corporate = CorporateAccount::forBranch($branch->id)->active()->find($request->integer('corporate_account_id'));

            if (! $corporate) {
                return back()->withErrors(['corporate_account_id' => 'The selected corporate account is not available for this property.']);
            }

            if ($corporate->negotiated_plan_id !== null) {
                $negotiated = RatePlan::forBranch($branch->id)->active()->find($corporate->negotiated_plan_id);

                if (! $negotiated) {
                    return back()->withErrors(['corporate_account_id' => 'The corporate negotiated plan is no longer active.']);
                }

                $ratePlan = $negotiated;
            }
        }

        $promo = null;

        if ($request->filled('promo_code')) {
            $promo = PromoCode::forBranch($branch->id)
                ->where('code', $request->string('promo_code')->value())
                ->first();

            if (! $promo) {
                return back()->withErrors(['promo_code' => 'Promo code not recognised for this property.']);
            }
        }

        try {
            $quote = $ratePlan !== null ? (new RateEngine)->price(
                $branch,
                $ratePlan,
                $roomType,
                $checkInDate,
                $checkOutDate,
                $promo,
                $corporate,
            ) : null;
        } catch (AvailabilityException $e) {
            return back()->withErrors(['rate_plan_id' => $e->getMessage()]);
        }

        // Plan-less properties keep the legacy totals path: no quote, no
        // snapshot, no promo/corporate stacking. Explicit commercial
        // inputs always need a resolvable plan.
        if ($quote === null && ($promo !== null || $corporate !== null)) {
            return back()->withErrors(['rate_plan_id' => 'Promo codes and corporate rates need an active rate plan for this property.']);
        }

        $reservation = $availability->reserve(
            branch: $branch,
            roomType: $roomType,
            checkIn: $checkInDate,
            checkOut: $checkOutDate,
            attributes: [
                'currency_code' => $branch->currency_code,
                'guest_id' => $guestId,
                'guest_name' => $guestName,
                'guest_email' => $guestEmail !== '' ? $guestEmail : null,
                'guest_phone' => $guestPhone !== '' ? $guestPhone : null,
                'guest_notes' => $request->string('guest_notes')->value(),
                'adults' => $adults,
                'children' => $children,
                'special_requests' => $specialRequests,
                'is_group_booking' => $isGroupBooking,
                'group_id' => $groupId !== '' ? $groupId : null,
                'room_rate' => $quote !== null
                    ? ($quote['nights'][0]['total_minor'] ?? $roomType->base_rate)
                    : ($pricing['per_night'][0]['rate'] ?? $roomType->base_rate),
                'total_amount' => $quote !== null ? $quote['total_minor'] : $pricing['total'],
                'status' => 'confirmed',
                'source' => 'direct',
                'payment_status' => 'pending',
            ],
            roomId: $roomId,
            idempotencyKey: is_string($idempotencyKey) && trim($idempotencyKey) !== '' ? trim($idempotencyKey) : null,
            overbookedBy: $overbookReason !== '' ? $user : null,
            overbookReason: $overbookReason !== '' ? $overbookReason : null,
            ratePlan: $ratePlan,
            restrictionOverrider: $overbookReason !== '' ? $user : null,
            restrictionReason: $overbookReason !== '' ? $overbookReason : null,
            rateQuote: $quote,
            promo: $promo,
            dnrOverrider: $request->string('dnr_override_reason')->value() !== '' ? $user : null,
            dnrReason: $request->string('dnr_override_reason')->value() !== '' ? $request->string('dnr_override_reason')->value() : null,
        );

        if ($roomId !== null) {
            $room = Room::find($roomId);
            if ($room && $room->status === 'available') {
                $room->update(['status' => 'reserved']);
            }
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Reservation '.$reservation->confirmation_number.' created.']);

        return redirect()->route('reservations.show', $reservation);
    }

    public function show(Request $request, Reservation $reservation): Response
    {
        $this->ensureBranchAccess($reservation->branch);

        $reservation->load(['room', 'roomType', 'branch', 'guest', 'ratePlan']);

        return Inertia::render('reservations/Show', [
            'reservation' => $reservation,
            'guarantee' => [
                'policy' => (new GuaranteeService)->policyFor($reservation->branch_id, $reservation->rate_plan_id),
                'can_waive_penalty' => $request->user()?->can('reservations.waive_penalty') ?? false,
            ],
            'upsells' => (new UpsellService)->quote($reservation->branch, $reservation),
            'can_grant_free_upsell' => $request->user()?->can('upsell.grant_free') ?? false,
        ]);
    }

    public function edit(Reservation $reservation): Response
    {
        $this->ensureBranchAccess($reservation->branch);

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

    public function update(Request $request, Reservation $reservation): RedirectResponse
    {
        $this->ensureBranchAccess($reservation->branch);

        $request->validate([
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
            'version' => 'nullable|integer|min:1',
        ]);

        $data = [];
        if ($request->has('room_type_id')) {
            $data['room_type_id'] = $request->integer('room_type_id');
        }
        if ($request->has('room_id')) {
            $data['room_id'] = $request->integer('room_id');
        }
        if ($request->has('branch_id')) {
            $data['branch_id'] = $request->integer('branch_id');
        }
        if ($request->has('guest_name')) {
            $data['guest_name'] = $request->string('guest_name')->value();
        }
        if ($request->has('guest_email')) {
            $email = $request->string('guest_email')->value();
            $data['guest_email'] = $email !== '' ? $email : null;
        }
        if ($request->has('guest_phone')) {
            $phone = $request->string('guest_phone')->value();
            $data['guest_phone'] = $phone !== '' ? $phone : null;
        }
        if ($request->has('guest_notes')) {
            $notes = $request->string('guest_notes')->value();
            $data['guest_notes'] = $notes !== '' ? $notes : null;
        }
        if ($request->has('adults')) {
            $data['adults'] = $request->integer('adults');
        }
        if ($request->has('children')) {
            $data['children'] = $request->integer('children');
        }
        if ($request->has('check_in_date')) {
            $data['check_in_date'] = $request->string('check_in_date')->value();
        }
        if ($request->has('check_out_date')) {
            $data['check_out_date'] = $request->string('check_out_date')->value();
        }
        if ($request->has('special_requests')) {
            $data['special_requests'] = $request->input('special_requests');
        }

        $newRoomId = $data['room_id'] ?? $reservation->room_id;
        $newCheckIn = $data['check_in_date'] ?? $reservation->check_in_date->format('Y-m-d');
        $newCheckOut = $data['check_out_date'] ?? $reservation->check_out_date->format('Y-m-d');

        if ($newRoomId) {
            $hasOverlap = Reservation::where('room_id', $newRoomId)
                ->where('id', '!=', $reservation->id)
                ->whereIn('status', ['confirmed', 'reserved', 'checked_in'])
                ->where('check_in_date', '<', $newCheckOut)
                ->where('check_out_date', '>', $newCheckIn)
                ->exists();

            if ($hasOverlap) {
                return back()->withErrors(['room_id' => 'This room is already booked for the selected dates.']);
            }
        }

        if ($request->has('version')) {
            $reservation->saveWithVersion($data, $request->integer('version'));
        } else {
            $reservation->update($data);
        }

        return $this->flashSuccess('Reservation updated.');
    }

    public function checkIn(Request $request, Reservation $reservation): RedirectResponse
    {
        $reservation->load(['branch', 'room', 'guest']);

        $this->ensureBranchAccess($reservation->branch);

        if ($reservation->status !== 'confirmed' && $reservation->status !== 'reserved') {
            return back()->withErrors(['status' => 'This reservation cannot be checked in.']);
        }

        // Front desk picks the room at check-in time when none is assigned.
        if (! $reservation->room_id) {
            $requestedRoomId = $request->integer('room_id');

            if ($requestedRoomId <= 0) {
                return back()->withErrors(['room_id' => 'No room assigned to this reservation.']);
            }

            $candidate = Room::find($requestedRoomId);

            if (! $candidate || $candidate->branch_id !== $reservation->branch_id) {
                abort(403, 'The assigned room does not belong to this property.');
            }

            if (! in_array($candidate->status, ['available', 'reserved'])) {
                return back()->withErrors(['room_id' => 'This room is not available.']);
            }

            $reservation->update(['room_id' => $candidate->id]);
            $reservation->load(['branch', 'room', 'guest']);
        }

        $room = $reservation->room;

        if (! $room || $room->branch_id !== $reservation->branch_id) {
            abort(403, 'The assigned room does not belong to this property.');
        }

        if (! in_array($room->status, ['available', 'reserved'])) {
            return back()->withErrors(['room_id' => 'This room is not available.']);
        }

        $hasOverlap = Reservation::where('room_id', $room->id)
            ->where('id', '!=', $reservation->id)
            ->whereIn('status', ['confirmed', 'reserved', 'checked_in'])
            ->where('check_in_date', '<', $reservation->check_out_date)
            ->where('check_out_date', '>', $reservation->check_in_date)
            ->exists();

        if ($hasOverlap) {
            return back()->withErrors(['room_id' => 'This room is already booked for the reservation dates.']);
        }

        try {
            (new AvailabilityService)->setRoomForRemainingNights(
                $reservation,
                $room,
                now()->toDateString(),
            );
        } catch (AvailabilityException $e) {
            return back()->withErrors(['room_id' => $e->getMessage()]);
        }

        $reservation->update([
            'status' => 'checked_in',
            'actual_check_in_at' => now(),
        ]);

        $room->update(['status' => 'occupied']);

        // Issue door lock key
        try {
            $lockService = new DoorLockService;
            $lockService->forBranch($reservation->branch)->issueKey($reservation);
        } catch (\Throwable $e) {
            Log::warning('Door lock key issuance failed', [
                'reservation' => $reservation->confirmation_number,
                'error' => $e->getMessage(),
            ]);
        }

        // Send check-in notification
        if ($reservation->guest_email && $reservation->guest) {
            $reservation->guest->notify(new CheckInNotification($reservation));
        }

        // Auto-pair in-room tablet
        try {
            $tabletService = new TabletService;
            $tabletService->pair($room, $reservation);
        } catch (\Throwable $e) {
            Log::warning('Tablet pairing failed', [
                'reservation' => $reservation->confirmation_number,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->flashSuccess('Guest checked in to room '.$room->number.'.');
    }

    public function checkOut(Reservation $reservation): RedirectResponse
    {
        $reservation->load(['branch', 'room', 'guest']);

        $this->ensureBranchAccess($reservation->branch);

        if ($reservation->status !== 'checked_in') {
            return back()->withErrors(['status' => 'This reservation is not checked in.']);
        }

        // Revoke door lock key
        try {
            $lockService = new DoorLockService;
            $lockService->forBranch($reservation->branch)->revokeKey($reservation);
        } catch (\Throwable $e) {
            Log::warning('Door lock key revocation failed', [
                'reservation' => $reservation->confirmation_number,
                'error' => $e->getMessage(),
            ]);
        }

        $reservation->update([
            'status' => 'checked_out',
            'actual_check_out_at' => now(),
        ]);

        // Return unsold future nights (early departure) to inventory.
        (new AvailabilityService)->releaseFromDate($reservation->fresh() ?? $reservation, now()->toDateString());

        if ($reservation->room) {
            $reservation->room->update(['status' => 'dirty']);
        }

        // Send checkout notification
        if ($reservation->guest_email && $reservation->guest) {
            $reservation->guest->notify(new CheckoutNotification($reservation));
        }

        // Auto-wipe in-room tablet
        try {
            $tabletService = new TabletService;
            $session = TabletSession::where('reservation_id', $reservation->id)
                ->whereNull('wiped_at')
                ->first();
            if ($session !== null) {
                $tabletService->wipeSession($session);
            }
        } catch (\Throwable $e) {
            Log::warning('Tablet wipe failed', [
                'reservation' => $reservation->confirmation_number,
                'error' => $e->getMessage(),
            ]);
        }

        // Update guest lifetime stats
        if ($reservation->guest) {
            $reservation->guest->incrementStay(
                $reservation->nights,
                $reservation->total_amount
            );
        }

        // Accrue OTA/agent commission (idempotent; gaps covered by
        // commissions:backfill). Checkout never fails on journal errors.
        try {
            (new CommissionService)->accrue($reservation->fresh() ?? $reservation);
        } catch (\Throwable $e) {
            Log::warning('Commission accrual failed', [
                'reservation' => $reservation->confirmation_number,
                'error' => $e->getMessage(),
            ]);
        }

        // Loyalty earn (idempotent per stay) + post-stay survey shell.
        try {
            (new LoyaltyService)->earn($reservation->fresh() ?? $reservation);
            PostStaySurvey::firstOrCreate(['reservation_id' => $reservation->id]);
        } catch (\Throwable $e) {
            Log::warning('Loyalty earn failed', [
                'reservation' => $reservation->confirmation_number,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->flashSuccess('Guest checked out from room '.$reservation->room?->number.'.');
    }

    public function cancel(Request $request, Reservation $reservation): RedirectResponse
    {
        $reservation->load(['branch', 'room']);

        $this->ensureBranchAccess($reservation->branch);

        if (in_array($reservation->status, ['checked_out', 'cancelled'], true)) {
            return back()->withErrors(['status' => 'This reservation cannot be cancelled.']);
        }

        $waive = $request->boolean('waive_penalty');

        if ($waive && ! ($request->user()?->can('reservations.waive_penalty') ?? false)) {
            abort(403, 'Waiving penalties requires the reservations.waive_penalty permission.');
        }

        try {
            $outcome = (new GuaranteeService)->cancelReservation($reservation, $request->user(), $waive);
        } catch (AvailabilityException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        if ($outcome['fee_minor'] > 0) {
            $formatted = number_format($outcome['fee_minor'] / 100, 2).' '.$reservation->branch->currency_code;

            return $this->flashSuccess('Reservation cancelled with a '.$formatted.' fee.');
        }

        return $this->flashSuccess($outcome['waived'] ? 'Reservation cancelled; penalty waived.' : 'Reservation cancelled.');
    }

    public function collectDeposit(Request $request, Reservation $reservation): RedirectResponse
    {
        $reservation->load(['branch']);

        $this->ensureBranchAccess($reservation->branch);

        $request->validate([
            'amount_minor' => 'required|integer|min:1',
            'method' => 'nullable|string|in:cash,card,online',
            'reference' => 'nullable|string|max:128',
        ]);

        try {
            (new GuaranteeService)->collectDeposit(
                $reservation,
                $request->integer('amount_minor'),
                $request->user(),
                $request->string('method', 'card')->value(),
                $request->string('reference')->value() ?: null,
            );
        } catch (AvailabilityException $e) {
            return back()->withErrors(['amount_minor' => $e->getMessage()]);
        }

        return $this->flashSuccess('Deposit collected.');
    }

    public function destroy(Reservation $reservation): RedirectResponse
    {
        $this->ensureBranchAccess($reservation->branch);

        if (in_array($reservation->status, ['checked_in'])) {
            return back()->withErrors(['status' => 'Cannot delete an active reservation.']);
        }

        $reservation->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Reservation deleted.']);

        return redirect()->route('reservations.index');
    }

    /**
     * Search availability across all branches for cross-branch booking.
     */
    public function crossBranchSearch(Request $request): Response
    {
        $request->validate([
            'check_in' => 'required|date|after_or_equal:today',
            'check_out' => 'required|date|after:check_in',
            'adults' => 'required|integer|min:1|max:10',
        ]);

        $checkIn = $request->string('check_in')->value();
        $checkOut = $request->string('check_out')->value();
        $adults = $request->integer('adults');

        $branches = Branch::active()->with('roomTypes')->get()
            ->each(function (Branch $branch) {
                $branch->setRelation('roomTypes', $branch->roomTypes->where('is_active', true));
                foreach ($branch->roomTypes as $rt) {
                    $totalRooms = $rt->rooms()->where('is_active', true)->count();
                    $rt->setAttribute('total_rooms', $totalRooms);
                }
            });

        $branchIds = $branches->pluck('id');
        $roomTypeIds = $branches->flatMap->roomTypes->pluck('id');

        // Bulk-fetch booked counts per branch+room_type
        /** @var array<int, array<int, int>> $bookedMap */
        $bookedMap = [];
        if ($branchIds->isNotEmpty() && $roomTypeIds->isNotEmpty()) {
            Reservation::whereIn('branch_id', $branchIds)
                ->whereIn('room_type_id', $roomTypeIds)
                ->whereIn('status', ['confirmed', 'reserved', 'checked_in'])
                ->where('check_in_date', '<', $checkOut)
                ->where('check_out_date', '>', $checkIn)
                ->select('branch_id', 'room_type_id', DB::raw('count(*) as booked_count'))
                ->groupBy('branch_id', 'room_type_id')
                ->get()
                ->each(function ($row) use (&$bookedMap) {
                    $bookedCount = is_numeric($row->getAttribute('booked_count')) ? (int) $row->getAttribute('booked_count') : 0;
                    $bookedMap[$row->branch_id][$row->room_type_id] = $bookedCount;
                });
        }

        $results = $branches->map(function (Branch $branch) use ($adults, $bookedMap) {
            /** @var Collection<int, RoomType> $roomTypes */
            $roomTypes = $branch->roomTypes->filter(fn (RoomType $rt) => $rt->max_occupancy >= $adults);

            $availableData = $roomTypes->map(function (RoomType $rt) use ($branch, $bookedMap) {
                $bookedCount = $bookedMap[$branch->id][$rt->id] ?? 0;
                $available = max(0, $rt->total_rooms - $bookedCount);

                return [
                    'room_type_id' => $rt->id,
                    'room_type_name' => $rt->name,
                    'base_rate' => $rt->base_rate,
                    'available_count' => $available,
                ];
            })->filter(fn (array $data) => $data['available_count'] > 0);

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
