<?php

namespace App\Services;

use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Models\Folio;
use App\Models\HotspotTier;
use App\Models\Reservation;
use App\Models\ReservationHotspot;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tier selection (pre-complete upsell) + per-guest auto-provision helpers.
 * Paid tiers post to the folio like other upsells; free tiers only record.
 */
class HotspotService
{
    /**
     * @return Collection<int, HotspotTier>
     */
    public function tiersFor(Branch $branch): Collection
    {
        return HotspotTier::forBranch($branch->id)->active()->orderBy('price_minor')->orderBy('id')->get();
    }

    public function defaultTier(Branch $branch): ?HotspotTier
    {
        $tiers = $this->tiersFor($branch);

        return $tiers->firstWhere('code', HotspotTier::CODE_FREE)
            ?? $tiers->firstWhere('price_minor', 0)
            ?? $tiers->first();
    }

    /**
     * Record the tier chosen before the reservation completes. Idempotent
     * per reservation: re-selecting the same tier returns the existing row;
     * changing tier posts an adjustment charge for paid tiers.
     */
    public function selectTier(Reservation $reservation, HotspotTier $tier, ?User $by = null, ?string $idempotencyKey = null): ReservationHotspot
    {
        if ($tier->branch_id !== $reservation->branch_id) {
            throw new AvailabilityException('HOTSPOT_BRANCH', 'Tier and reservation properties differ.');
        }

        if (! $tier->is_active) {
            throw new AvailabilityException('HOTSPOT_UNAVAILABLE', 'This Wi-Fi tier is no longer available.');
        }

        return DB::transaction(function () use ($reservation, $tier, $by, $idempotencyKey) {
            $locked = Reservation::where('id', $reservation->id)->lockForUpdate()->firstOrFail();
            $branch = Branch::findOrFail($locked->branch_id);

            $existing = ReservationHotspot::where('reservation_id', $locked->id)->lockForUpdate()->first();

            if ($existing && (int) $existing->hotspot_tier_id === (int) $tier->id) {
                return $existing;
            }

            $fee = max(0, (int) $tier->price_minor);
            $transactionId = $existing?->folio_transaction_id;

            if ($fee > 0) {
                $folio = Folio::where('reservation_id', $locked->id)->where('status', 'open')->first()
                    ?? (new FolioService)->createFolio($branch->id, $locked->id);

                $charge = (new FolioService)->postDebit(
                    $folio,
                    'wifi',
                    "Wi-Fi {$tier->name}: {$locked->confirmation_number}",
                    $fee,
                    $by?->id,
                    windowCode: 'room',
                    metadata: [
                        'hotspot_tier_id' => $tier->id,
                        'hotspot_tier_code' => $tier->code,
                        'idempotency_key' => $idempotencyKey,
                    ],
                );

                $transactionId = $charge->id;
            }

            $row = $existing ?? new ReservationHotspot(['reservation_id' => $locked->id]);
            $row->hotspot_tier_id = $tier->id;
            $row->fee_minor = $fee;
            $row->folio_transaction_id = $transactionId;
            $row->save();

            return $row->fresh() ?? $row;
        }, 3);
    }

    /**
     * Ensure a reservation carries a tier (free default). Called from all
     * reservation creation paths so fulfilment has one hook. Returns null
     * when the property has no tiers configured and none was requested,
     * so reservation creation never breaks on unconfigured branches.
     */
    public function attachReservation(Reservation $reservation, ?int $tierId = null, ?User $by = null): ?ReservationHotspot
    {
        $branch = Branch::findOrFail($reservation->branch_id);

        $tier = null;

        if ($tierId !== null) {
            $tier = HotspotTier::forBranch($branch->id)->active()->find($tierId);

            if (! $tier) {
                throw new AvailabilityException('HOTSPOT_UNAVAILABLE', 'The selected Wi-Fi tier is not available for this property.');
            }
        } else {
            $tier = $this->defaultTier($branch);

            if (! $tier) {
                return null;
            }
        }

        return $this->selectTier($reservation, $tier, $by);
    }

    /**
     * Unambiguous voucher alphabet (no 0/O/1/I).
     */
    public function generateVoucher(int $length = 12): string
    {
        $length = max(8, $length);
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }

    public function generatePassword(): string
    {
        return Str::upper(Str::random(12));
    }
}
