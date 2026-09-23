<?php

namespace App\Services;

use App\Events\PointsEarned;
use App\Events\PointsRedeemed;
use App\Events\TierChanged;
use App\Exceptions\AvailabilityException;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyLedger;
use App\Models\LoyaltyTier;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Points wallets: earn on checkout (idempotent per stay), redeem as
 * folio credit at a fixed minor rate, tiers from lifetime nights.
 * Balances move only through ledger rows under row locks.
 */
class LoyaltyService
{
    /**
     * Points buy folio credit at this many minor units each.
     */
    public const REDEEM_RATE_MINOR = 10;

    /**
     * Earn for a checked-out stay: 1 point per night × tier earn rate.
     * Double checkouts collapse onto the original ledger row.
     */
    public function earn(Reservation $reservation, ?User $by = null): ?LoyaltyLedger
    {
        if ($reservation->guest_id === null) {
            return null;
        }

        return DB::transaction(function () use ($reservation) {
            $guest = Guest::where('id', $reservation->guest_id)->lockForUpdate()->first();

            if (! $guest) {
                return null;
            }

            $account = LoyaltyAccount::where('guest_id', $guest->id)->lockForUpdate()->first()
                ?? $this->enroll($guest);

            $nights = max(1, (int) $reservation->check_in_date->diffInDays($reservation->check_out_date));
            $tier = LoyaltyTier::where('name', $account->tier)->first();
            $earnBps = $tier instanceof LoyaltyTier ? $tier->earn_bps : 10000;
            $points = (int) round($nights * $earnBps / 10000);

            if ($points < 1) {
                return null;
            }

            $existing = LoyaltyLedger::where('idempotency_key', "loyalty.earn.{$reservation->id}")->first();

            if ($existing) {
                return $existing;
            }

            try {
                // Savepoint: a lost creation race rolls back to here,
                // leaving the outer transaction usable for the rescue.
                $entry = DB::transaction(fn () => LoyaltyLedger::create([
                    'loyalty_account_id' => $account->id,
                    'delta' => $points,
                    'reason' => 'stay_earn',
                    'source_type' => $reservation->getMorphClass(),
                    'source_id' => $reservation->id,
                    'idempotency_key' => "loyalty.earn.{$reservation->id}",
                ]), 1);
            } catch (QueryException $e) {
                $code = $e->getPrevious()?->getCode();

                if ($code !== '23000' && $code !== '23505') {
                    throw $e;
                }

                return LoyaltyLedger::where('idempotency_key', "loyalty.earn.{$reservation->id}")->firstOrFail();
            }

            $account->update(['points' => $account->points + $points]);
            $this->recalcTier($account->fresh() ?? $account);

            event(new PointsEarned($account->fresh() ?? $account, $entry, $points));

            return $entry;
        });
    }

    /**
     * Redeem points as folio credit. Concurrent redeems serialize on
     * the account row; overspend is refused, never partial. Same-guest
     * folios only. Pass the request idempotency key for retry safety.
     */
    public function redeem(LoyaltyAccount $account, Folio $folio, int $points, User $by, ?string $idempotencyKey = null): LoyaltyLedger
    {
        if ($points < 1) {
            throw new AvailabilityException('LOYALTY_POINTS', 'Redemption needs at least one point.');
        }

        return DB::transaction(function () use ($account, $folio, $points, $by, $idempotencyKey) {
            $locked = LoyaltyAccount::where('id', $account->id)->lockForUpdate()->firstOrFail();

            // Replay first: an already-spent balance must not mask the
            // original entry (same discipline as earn()).
            if ($idempotencyKey !== null) {
                $replay = LoyaltyLedger::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();

                if ($replay) {
                    return $replay;
                }
            }

            if ($locked->points < $points) {
                throw new AvailabilityException('LOYALTY_BALANCE', "Only {$locked->points} points available.");
            }

            if ($folio->reservation_id === null) {
                throw new AvailabilityException('LOYALTY_FOLIO', 'Redemption needs a reservation folio.');
            }

            $holder = $folio->reservation?->guest_id;

            if ($holder === null || $holder !== $locked->guest_id) {
                throw new AvailabilityException('LOYALTY_GUEST', 'Points redeem only against the holder folio.');
            }

            $credit = $points * self::REDEEM_RATE_MINOR;

            (new FolioService)->postCredit(
                $folio,
                'loyalty_redeem',
                "Loyalty redemption: {$points} pts",
                $credit,
                $by->id,
                referenceType: LoyaltyLedger::class,
                journalEvent: 'loyalty.redeemed',
            );

            try {
                $entry = DB::transaction(fn () => LoyaltyLedger::create([
                    'loyalty_account_id' => $locked->id,
                    'delta' => -$points,
                    'reason' => 'redeem',
                    'source_type' => $folio->getMorphClass(),
                    'source_id' => $folio->id,
                    'idempotency_key' => $idempotencyKey ?? 'loyalty.redeem.'.$folio->id.'.'.$points.'.'.Str::uuid()->toString(),
                ]), 1);
            } catch (QueryException $e) {
                $code = $e->getPrevious()?->getCode();

                if (($code === '23000' || $code === '23505') && $idempotencyKey !== null) {
                    return LoyaltyLedger::where('idempotency_key', $idempotencyKey)->firstOrFail();
                }

                throw $e;
            }

            $locked->update(['points' => $locked->points - $points]);

            event(new PointsRedeemed($locked->fresh() ?? $locked, $entry, $points));

            return $entry;
        });
    }

    /**
     * Enroll a guest (idempotent): zero-point member wallet.
     */
    public function enroll(Guest $guest): LoyaltyAccount
    {
        return LoyaltyAccount::firstOrCreate(
            ['guest_id' => $guest->id],
            ['points' => 0, 'tier' => 'member'],
        );
    }

    /**
     * Recompute tier from lifetime nights. Fires TierChanged exactly
     * on transitions.
     */
    public function recalcTier(LoyaltyAccount $account): LoyaltyAccount
    {
        return DB::transaction(function () use ($account) {
            $locked = LoyaltyAccount::where('id', $account->id)->lockForUpdate()->firstOrFail();
            $total = Reservation::where('guest_id', $locked->guest_id)
                ->where('status', 'checked_out')
                ->sum(DB::raw('GREATEST(check_out_date - check_in_date, 1)'));
            $nights = (int) $total;

            $tier = 'member';
            foreach (LoyaltyTier::orderBy('threshold_nights')->get() as $candidate) {
                if ($nights >= $candidate->threshold_nights) {
                    $tier = $candidate->name;
                }
            }

            if ($tier !== $locked->tier) {
                $locked->update(['tier' => $tier]);

                event(new TierChanged($locked->fresh() ?? $locked, $tier));
            }

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * Nightly tier sweep across accounts with checked-out stays.
     *
     * @return array{checked: int, upgraded: int}
     */
    public function recalcAll(): array
    {
        $checked = 0;
        $upgraded = 0;

        LoyaltyAccount::orderBy('id')->chunkById(200, function ($accounts) use (&$checked, &$upgraded) {
            foreach ($accounts as $account) {
                $before = $account->tier;
                $after = $this->recalcTier($account)->tier;
                $checked++;

                if ($after !== $before) {
                    $upgraded++;
                }
            }
        });

        return ['checked' => $checked, 'upgraded' => $upgraded];
    }
}
