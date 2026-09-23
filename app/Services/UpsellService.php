<?php

namespace App\Services;

use App\Events\UpsellAccepted;
use App\Events\UpsellExpired;
use App\Events\UpsellOffered;
use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Models\Folio;
use App\Models\Reservation;
use App\Models\ReservationNight;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\UpsellAcceptance;
use App\Models\UpsellOffer;
use App\Models\User;
use App\Services\DoorLock\DoorLockService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Priced ancillaries: early check-in, late checkout, room upgrades.
 * Quotes re-check live inventory inside the accept transaction, so a
 * stale quote can never sell a room that just filled. Fees freeze on
 * the folio; free grants post zero lines for the audit trail.
 */
class UpsellService
{
    /**
     * Eligible quotes for a reservation. Pure reads (plus an optional
     * offer announcement for desk/push surfaces).
     *
     * @return list<array{offer_id: int, kind: string, fee_minor: int, eligible: bool, reason: string|null}>
     */
    public function quote(Branch $branch, Reservation $reservation, bool $announce = false): array
    {
        $quotes = [];

        foreach (UpsellOffer::forBranch($branch->id)->active()->orderBy('id')->get() as $offer) {
            try {
                $fee = $this->checkEligible($branch, $offer, $reservation);
                $quotes[] = ['offer_id' => $offer->id, 'kind' => $offer->kind, 'fee_minor' => $fee, 'eligible' => true, 'reason' => null];

                if ($announce) {
                    event(new UpsellOffered($offer, $reservation));
                }
            } catch (AvailabilityException $e) {
                $quotes[] = ['offer_id' => $offer->id, 'kind' => $offer->kind, 'fee_minor' => 0, 'eligible' => false, 'reason' => $e->getMessage()];
            }
        }

        return $quotes;
    }

    /**
     * Accept an offer: re-guarded, fee posted, kind effects applied,
     * idempotent on the key. Expiry past → 410-style refusal.
     */
    public function accept(
        Reservation $reservation,
        UpsellOffer $offer,
        User $by,
        string $idempotencyKey,
        bool $free = false,
        ?string $expiresAt = null,
    ): UpsellAcceptance {
        if ($expiresAt !== null && Carbon::parse($expiresAt)->isPast()) {
            $acceptance = UpsellAcceptance::where('idempotency_key', $idempotencyKey)->first();

            if ($acceptance) {
                return $acceptance;
            }

            event(new UpsellExpired($offer, $reservation));

            throw new AvailabilityException('UPSELL_EXPIRED', 'This offer has expired.');
        }

        return DB::transaction(function () use ($reservation, $offer, $by, $idempotencyKey, $free, $expiresAt) {
            $replay = UpsellAcceptance::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();

            if ($replay) {
                return $replay;
            }

            $lockedOffer = UpsellOffer::where('id', $offer->id)->lockForUpdate()->firstOrFail();
            $lockedReservation = Reservation::where('id', $reservation->id)->lockForUpdate()->firstOrFail();
            $branch = Branch::findOrFail($lockedReservation->branch_id);

            if ($lockedOffer->branch_id !== $branch->id || ! $lockedOffer->active) {
                throw new AvailabilityException('UPSELL_UNAVAILABLE', 'This offer is no longer available.');
            }

            if ($lockedReservation->branch_id !== $branch->id) {
                throw new AvailabilityException('UPSELL_BRANCH', 'Offer and reservation properties differ.');
            }

            $fee = $free ? 0 : $this->checkEligible($branch, $lockedOffer, $lockedReservation);

            $folio = Folio::where('reservation_id', $lockedReservation->id)->where('status', 'open')->first()
                ?? (new FolioService)->createFolio($branch->id, $lockedReservation->id);

            $this->applyKind($branch, $lockedOffer, $lockedReservation, $by);

            $charge = null;

            if ($fee > 0 || $free) {
                $charge = (new FolioService)->postDebit(
                    $folio,
                    $this->categoryFor($lockedOffer->kind),
                    $this->descriptionFor($lockedOffer, $lockedReservation, $free),
                    $fee,
                    $by->id,
                    windowCode: 'room',
                    metadata: ['upsell_offer_id' => $lockedOffer->id],
                );
            }

            $acceptance = UpsellAcceptance::create([
                'reservation_id' => $lockedReservation->id,
                'upsell_offer_id' => $lockedOffer->id,
                'fee_minor' => $fee,
                'idempotency_key' => $idempotencyKey,
                'expires_at' => $expiresAt,
            ]);

            event(new UpsellAccepted($lockedOffer->fresh() ?? $lockedOffer, $lockedReservation->fresh() ?? $lockedReservation, $acceptance, $charge?->id));

            return $acceptance;
        }, 3);
    }

    /**
     * Live eligibility check shared by quote and accept. Returns the
     * priced fee or throws the blocking reason.
     */
    private function checkEligible(Branch $branch, UpsellOffer $offer, Reservation $reservation): int
    {
        $fee = $offer->ruleInt('fee_minor', 0);

        if ($reservation->branch_id !== $branch->id) {
            throw new AvailabilityException('UPSELL_BRANCH', 'Offer and reservation properties differ.');
        }

        $today = Carbon::today()->toDateString();
        $availability = new AvailabilityService;

        if ($offer->kind === UpsellOffer::KIND_EARLY_CHECKIN) {
            if (! in_array($reservation->status, ['reserved', 'confirmed'], true)) {
                throw new AvailabilityException('UPSELL_STATE', 'Early check-in sells before arrival only.');
            }

            return $fee;
        }

        if ($offer->kind === UpsellOffer::KIND_LATE_CHECKOUT) {
            if ($reservation->status !== 'checked_in') {
                throw new AvailabilityException('UPSELL_STATE', 'Late checkout sells during the stay only.');
            }

            if ($offer->ruleInt('inventory_guard', 1) === 1) {
                // Read via attributes: walk-ins carry no room type and
                // cannot pass an inventory guard.
                $typeId = $reservation->getAttribute('room_type_id');
                $roomType = is_int($typeId) ? RoomType::find($typeId) : null;

                if ($roomType === null) {
                    throw new AvailabilityException('UPSELL_TARGET', 'Late checkout needs an assigned room type.');
                }

                if ($availability->sellableFor($branch, $roomType, $today) < 1) {
                    throw new AvailabilityException('ROOM_SOLD_TONIGHT', 'No rooms left tonight for a late checkout.');
                }
            }

            return $fee;
        }

        if ($offer->kind === UpsellOffer::KIND_UPGRADE) {
            if (! in_array($reservation->status, ['reserved', 'confirmed', 'checked_in'], true)) {
                throw new AvailabilityException('UPSELL_STATE', 'Upgrades sell on active reservations only.');
            }

            $target = $this->targetType($branch, $offer);

            if (! $target) {
                throw new AvailabilityException('UPSELL_TARGET', 'This upgrade has no target room type.');
            }

            foreach ($availability->nights($reservation->check_in_date->toDateString(), $reservation->check_out_date->toDateString()) as $date) {
                if ($date < $today) {
                    continue;
                }

                if ($availability->sellableFor($branch, $target, $date) < 1) {
                    throw new AvailabilityException('UPGRADE_SOLD_OUT', "No {$target->name} rooms left for {$date}.");
                }
            }

            return $fee;
        }

        throw new AvailabilityException('UPSELL_KIND', "Unknown upsell kind {$offer->kind}.");
    }

    private function applyKind(Branch $branch, UpsellOffer $offer, Reservation $reservation, User $by): void
    {
        if ($offer->kind === UpsellOffer::KIND_LATE_CHECKOUT) {
            $this->reissueKey($branch, $reservation);
        }

        if ($offer->kind === UpsellOffer::KIND_UPGRADE) {
            $target = $this->targetType($branch, $offer);

            if (! $target) {
                throw new AvailabilityException('UPSELL_TARGET', 'This upgrade has no target room type.');
            }

            $room = $this->freeRoom($branch, $target, $reservation);

            if (! $room) {
                throw new AvailabilityException('UPGRADE_SOLD_OUT', 'No upgrade room free for the remaining nights.');
            }

            // The move reissues the key itself; its gateway failure
            // rolls the whole acceptance back (no paid-but-unmoved).
            (new RoomMoveService)->move($reservation, $room, $by, null, ['reason' => "Upgrade via offer #{$offer->id}"]);
        }
    }

    private function targetType(Branch $branch, UpsellOffer $offer): ?RoomType
    {
        $rules = $offer->rules;
        $typeId = is_array($rules) ? ($rules['target_room_type_id'] ?? null) : null;

        if (! is_int($typeId)) {
            return null;
        }

        $type = RoomType::find($typeId);

        return $type && $type->branch_id === $branch->id ? $type : null;
    }

    private function freeRoom(Branch $branch, RoomType $target, Reservation $reservation): ?Room
    {
        $today = Carbon::today()->toDateString();
        $availability = new AvailabilityService;
        $nights = [];

        foreach ($availability->nights($reservation->check_in_date->toDateString(), $reservation->check_out_date->toDateString()) as $date) {
            if ($date >= $today) {
                $nights[] = $date;
            }
        }

        if ($nights === []) {
            return null;
        }

        $rooms = Room::where('branch_id', $branch->id)
            ->where('room_type_id', $target->id)
            ->where('is_active', true)
            ->where('status', 'available')
            ->orderBy('id')
            ->get();

        foreach ($rooms as $room) {
            $conflict = ReservationNight::where('room_id', $room->id)
                ->whereIn('stay_date', $nights)
                ->where('reservation_id', '!=', $reservation->id)
                ->whereHas('reservation', fn ($q) => $q->whereIn('status', ['pending', 'confirmed', 'reserved', 'checked_in'])->whereNull('deleted_at'))
                ->exists();

            if (! $conflict) {
                return $room;
            }
        }

        return null;
    }

    private function reissueKey(Branch $branch, Reservation $reservation): void
    {
        try {
            $locks = new DoorLockService;
            $locks->forBranch($branch)->revokeKey($reservation);
            $locks->forBranch($branch)->issueKey($reservation->fresh() ?? $reservation);
        } catch (\Throwable $e) {
            // The folio charge stands; desk staff reissue by hand.
            Log::warning('Upsell key reissue failed', [
                'reservation' => $reservation->confirmation_number,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function categoryFor(string $kind): string
    {
        return match ($kind) {
            UpsellOffer::KIND_EARLY_CHECKIN => 'early_checkin_fee',
            UpsellOffer::KIND_LATE_CHECKOUT => 'late_checkout_fee',
            default => 'upgrade_fee',
        };
    }

    private function descriptionFor(UpsellOffer $offer, Reservation $reservation, bool $free): string
    {
        $label = match ($offer->kind) {
            UpsellOffer::KIND_EARLY_CHECKIN => 'Early check-in',
            UpsellOffer::KIND_LATE_CHECKOUT => 'Late checkout',
            default => 'Room upgrade',
        };

        return $label.": {$reservation->confirmation_number}".($free ? ' (granted free)' : '');
    }
}
