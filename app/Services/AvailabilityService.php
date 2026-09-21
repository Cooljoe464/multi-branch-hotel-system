<?php

namespace App\Services;

use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Models\PromoCode;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\ReservationNight;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\RoomTypeInventory;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Per-date availability engine: the single source of truth for sell.
 *
 * Reads (quote) never lock. Writes (reserve/release) lock one inventory
 * row per night in date order inside a single transaction, so concurrent
 * bookings for the same night serialize and oversells are impossible
 * without an explicit, permissioned overbook.
 */
class AvailabilityService
{
    /**
     * Nights in [checkIn, checkOut).
     *
     * @return list<string>
     */
    public function nights(string $checkIn, string $checkOut): array
    {
        $start = Carbon::parse($checkIn)->startOfDay();
        $end = Carbon::parse($checkOut)->startOfDay();

        if (! $end->greaterThan($start)) {
            throw new InvalidArgumentException('Check-out must be after check-in.');
        }

        $nights = [];
        // Reassigned (not mutated in place): now() is CarbonImmutable
        // app-wide, so code must not rely on mutable stepping.
        for ($day = $start->copy(); $day->lessThan($end); $day = $day->addDay()) {
            $nights[] = $day->toDateString();
        }

        return $nights;
    }

    /**
     * @return array{available: bool, sellable_per_night: array<string, int>, unavailable_dates: list<string>}
     */
    public function quote(Branch $branch, RoomType $roomType, string $checkIn, string $checkOut): array
    {
        $sellable = [];
        $unavailable = [];

        foreach ($this->nights($checkIn, $checkOut) as $date) {
            $row = $this->row($branch, $roomType, $date);
            $sellable[$date] = $row->sellable();

            if ($row->sellable() < 1) {
                $unavailable[] = $date;
            }
        }

        return [
            'available' => $unavailable === [],
            'sellable_per_night' => $sellable,
            'unavailable_dates' => $unavailable,
        ];
    }

    /**
     * Reserve one unit per night and create the reservation atomically.
     *
     * Idempotent on $idempotencyKey (scope availability.reserve): replaying
     * returns the original reservation without consuming new inventory.
     *
     * @param  array<string, mixed>  $attributes  Reservation attributes (must include branch-compatible room_type_id).
     * @param  array<string, mixed>|null  $rateQuote  Frozen RateEngine quote; its totals are stored as the rate snapshot.
     */
    public function reserve(
        Branch $branch,
        RoomType $roomType,
        string $checkIn,
        string $checkOut,
        array $attributes,
        ?int $roomId = null,
        ?string $idempotencyKey = null,
        ?User $overbookedBy = null,
        ?string $overbookReason = null,
        ?RatePlan $ratePlan = null,
        ?User $restrictionOverrider = null,
        ?string $restrictionReason = null,
        ?array $rateQuote = null,
        ?PromoCode $promo = null,
    ): Reservation {
        if ($idempotencyKey !== null) {
            $existing = Reservation::where('branch_id', $branch->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($branch, $roomType, $checkIn, $checkOut, $attributes, $roomId, $idempotencyKey, $overbookedBy, $overbookReason, $ratePlan, $restrictionOverrider, $restrictionReason, $rateQuote, $promo) {
            if ($idempotencyKey !== null) {
                $existing = Reservation::where('branch_id', $branch->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return $existing;
                }
            }

            $nights = $this->nights($checkIn, $checkOut);
            $room = $roomId !== null ? Room::find($roomId) : null;

            if ($roomId !== null && (! $room || $room->branch_id !== $branch->id)) {
                throw new AvailabilityException('ROOM_NOT_IN_BRANCH', 'The selected room does not belong to this property.');
            }

            if ($room !== null && $room->room_type_id !== $roomType->id) {
                throw new AvailabilityException('ROOM_TYPE_MISMATCH', 'The selected room is not of the requested room type.');
            }

            $rows = [];
            foreach ($nights as $date) {
                $rows[$date] = $this->lockedRow($branch, $roomType, $date);
            }

            $unavailable = [];
            foreach ($rows as $date => $row) {
                if ($row->sellable() < 1) {
                    $unavailable[] = $date;
                }
            }

            $overbooked = $unavailable !== [];

            if ($overbooked) {
                $this->guardOverbook($overbookedBy, $overbookReason, $unavailable);
            }

            if ($ratePlan !== null) {
                (new RestrictionService)->evaluate(
                    $branch, $ratePlan, $roomType, $checkIn, $checkOut,
                    $restrictionOverrider, $restrictionReason,
                );
            }

            if ($rateQuote !== null && $promo !== null) {
                (new RateEngine)->consumePromo($promo);
            }

            if ($room !== null) {
                $this->claimRoom($room, $nights);
            }

            $metadata = $attributes['metadata'] ?? [];
            $metadata = is_array($metadata) ? $metadata : [];

            /** @var Reservation $reservation */
            $reservation = Reservation::create(array_merge($attributes, [
                'branch_id' => $branch->id,
                'room_type_id' => $roomType->id,
                'room_id' => $room?->id,
                'check_in_date' => $checkIn,
                'check_out_date' => $checkOut,
                'business_date' => $attributes['business_date'] ?? $checkIn,
                'idempotency_key' => $idempotencyKey,
                'overbooked' => $overbooked,
                'rate_plan_id' => $rateQuote !== null ? ($rateQuote['plan_id'] ?? null) : ($attributes['rate_plan_id'] ?? null),
                'promo_code_id' => $rateQuote !== null ? ($rateQuote['promo_code_id'] ?? null) : ($attributes['promo_code_id'] ?? null),
                'corporate_account_id' => $rateQuote !== null ? ($rateQuote['corporate_account_id'] ?? null) : ($attributes['corporate_account_id'] ?? null),
                'rate_snapshot' => $rateQuote,
                'metadata' => array_merge($metadata, $overbooked ? [
                    'overbooked_by' => $overbookedBy?->id,
                    'overbook_reason' => $overbookReason,
                ] : []),
            ]));

            foreach ($nights as $date) {
                ReservationNight::create([
                    'reservation_id' => $reservation->id,
                    'room_id' => $room?->id,
                    'stay_date' => $date,
                ]);

                $rows[$date]->increment('sold');
            }

            $booked = $reservation->fresh() ?? $reservation;

            (new GuaranteeService)->applyOnReserve($booked);

            return $booked->fresh() ?? $booked;
        }, 3);
    }

    /**
     * Release a reservation's nights. Safe to call twice.
     */
    public function release(Reservation $reservation): void
    {
        DB::transaction(function () use ($reservation) {
            $nights = ReservationNight::forReservation($reservation->id)->get();

            if ($nights->isEmpty()) {
                return;
            }

            $roomTypeId = $reservation->room_type_id;

            foreach ($nights as $night) {
                RoomTypeInventory::forRoomType($roomTypeId)
                    ->where('stay_date', $night->stay_date->toDateString())
                    ->lockForUpdate()
                    ->decrement('sold');
            }

            ReservationNight::forReservation($reservation->id)->delete();
        }, 3);
    }

    /**
     * Release nights on/after a date (early departure, checkout).
     * Nights already consumed stay posted; only future sell returns.
     */
    public function releaseFromDate(Reservation $reservation, string $fromDate): int
    {
        return DB::transaction(function () use ($reservation, $fromDate) {
            $nights = ReservationNight::forReservation($reservation->id)
                ->where('stay_date', '>=', $fromDate)
                ->lockForUpdate()
                ->get();

            foreach ($nights as $night) {
                RoomTypeInventory::forRoomType($reservation->room_type_id)
                    ->where('stay_date', $night->stay_date->toDateString())
                    ->lockForUpdate()
                    ->decrement('sold');
            }

            $released = $nights->count();

            ReservationNight::forReservation($reservation->id)
                ->where('stay_date', '>=', $fromDate)
                ->delete();

            return $released;
        }, 3);
    }

    /**
     * Point remaining nights at the assigned room (check-in, room move).
     * Fails when the room is held by another active reservation.
     */
    public function setRoomForRemainingNights(Reservation $reservation, Room $room, string $fromDate): void
    {
        DB::transaction(function () use ($reservation, $room, $fromDate) {
            if ($room->branch_id !== $reservation->branch_id) {
                throw new AvailabilityException('ROOM_NOT_IN_BRANCH', 'The room does not belong to this property.');
            }

            $nights = ReservationNight::forReservation($reservation->id)
                ->where('stay_date', '>=', $fromDate)
                ->lockForUpdate()
                ->get();

            foreach ($nights as $night) {
                $conflict = ReservationNight::where('room_id', $room->id)
                    ->where('stay_date', $night->stay_date->toDateString())
                    ->where('reservation_id', '!=', $reservation->id)
                    ->whereHas('reservation', fn ($q) => $q->whereIn('status', ['pending', 'confirmed', 'reserved', 'checked_in']))
                    ->exists();

                if ($conflict) {
                    throw new AvailabilityException(
                        'ROOM_CONFLICT',
                        "Room {$room->number} is already held for {$night->stay_date->toDateString()}.",
                        [$night->stay_date->toDateString()]
                    );
                }
            }

            ReservationNight::forReservation($reservation->id)
                ->where('stay_date', '>=', $fromDate)
                ->update(['room_id' => $room->id]);
        }, 3);
    }

    /**
     * Current sellable count for one night (unlocked read).
     */
    public function sellableFor(Branch $branch, RoomType $roomType, string $date): int
    {
        return $this->row($branch, $roomType, $date)->sellable();
    }

    /**
     * Branch default rate plan (best available rate) for restriction
     * evaluation on plan-less bookings.
     */
    public function defaultPlan(Branch $branch): ?RatePlan
    {
        return RatePlan::forBranch($branch->id)
            ->active()
            ->orderByRaw("CASE WHEN type = 'bar' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->first();
    }

    private function row(Branch $branch, RoomType $roomType, string $date): RoomTypeInventory
    {
        return RoomTypeInventory::forRoomType($roomType->id)
            ->where('stay_date', $date)
            ->first() ?? $this->blankRow($branch, $roomType, $date);
    }

    private function blankRow(Branch $branch, RoomType $roomType, string $date): RoomTypeInventory
    {
        return new RoomTypeInventory([
            'branch_id' => $branch->id,
            'room_type_id' => $roomType->id,
            'stay_date' => $date,
            'total_rooms' => $this->physicalCount($branch, $roomType),
            'sold' => 0,
            'blocked' => 0,
            'overbooking_limit' => $this->limitFor($branch, $this->physicalCount($branch, $roomType)),
        ]);
    }

    /**
     * Lock the night's row, creating it on first sale. Two concurrent
     * creators race the insert; the loser catches the unique violation
     * inside a savepoint (so the outer transaction survives on
     * PostgreSQL) and re-locks the winner's row.
     */
    private function lockedRow(Branch $branch, RoomType $roomType, string $date): RoomTypeInventory
    {
        $row = RoomTypeInventory::forRoomType($roomType->id)
            ->where('stay_date', $date)
            ->lockForUpdate()
            ->first();

        if ($row) {
            return $row;
        }

        try {
            return DB::transaction(fn () => $this->openRow($branch, $roomType, $date));
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }
        }

        return RoomTypeInventory::forRoomType($roomType->id)
            ->where('stay_date', $date)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function openRow(Branch $branch, RoomType $roomType, string $date): RoomTypeInventory
    {
        $row = $this->blankRow($branch, $roomType, $date);
        $row->save();

        return $row;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $code = $e->getPrevious()?->getCode();

        return $code === '23000' || $code === '23505';
    }

    private function physicalCount(Branch $branch, RoomType $roomType): int
    {
        return Room::where('branch_id', $branch->id)
            ->where('room_type_id', $roomType->id)
            ->where('is_active', true)
            ->count();
    }

    private function limitFor(Branch $branch, int $total): int
    {
        /** @var array{mode?: string, value?: int}|null $policy */
        $policy = $branch->overbooking_policy;
        $mode = $policy['mode'] ?? 'none';
        $value = (int) ($policy['value'] ?? 0);

        if ($value <= 0) {
            return 0;
        }

        return match ($mode) {
            'count' => $value,
            'percent' => (int) floor($total * $value / 100),
            default => 0,
        };
    }

    /**
     * A requested physical room is usable only when no other active hold
     * covers the same room-night.
     */
    private function roomFree(?Room $room, string $date): bool
    {
        if ($room === null) {
            return false;
        }

        return ! ReservationNight::where('room_id', $room->id)
            ->where('stay_date', $date)
            ->whereHas('reservation', fn ($q) => $q->whereIn('status', ['pending', 'confirmed', 'reserved', 'checked_in'])->whereNull('deleted_at'))
            ->exists();
    }

    /**
     * Claim a physical room for every night, failing fast on conflict.
     *
     * @param  list<string>  $nights
     */
    private function claimRoom(Room $room, array $nights): void
    {
        foreach ($nights as $date) {
            if (! $this->roomFree($room, $date)) {
                throw new AvailabilityException('ROOM_CONFLICT', "Room {$room->number} is already held for {$date}.", [$date]);
            }
        }
    }

    /**
     * @param  list<string>  $unavailable
     */
    private function guardOverbook(?User $overbookedBy, ?string $overbookReason, array $unavailable): void
    {
        if ($overbookedBy === null || trim((string) $overbookReason) === '') {
            throw new AvailabilityException('SOLD_OUT', 'No availability for the requested dates.', $unavailable);
        }

        if (! $overbookedBy->can('availability.override_overbook') && ! ($overbookedBy->is_global_admin ?? false)) {
            throw new AvailabilityException('OVERBOOK_FORBIDDEN', 'Overbooking requires the availability.override_overbook permission.', $unavailable);
        }
    }
}
