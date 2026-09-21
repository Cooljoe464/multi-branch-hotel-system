<?php

namespace App\Services;

use App\Events\CommissionAccrued;
use App\Events\PayoutCompleted;
use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Models\CommissionAccrual;
use App\Models\CommissionPayout;
use App\Models\CommissionRule;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * OTA/agent commission accrual and payout. Accruals freeze rate and
 * base at checkout from the reservation's rate snapshot, so later
 * rule edits never restate history. Journals: Dr Commission Expense /
 * Cr Commission Payable on accrue; payable clears on pay.
 */
class CommissionService
{
    /**
     * Accrue commission for a checked-out stay. Idempotent on
     * commission.{reservation_id}: re-runs return the original row
     * without re-journaling. Returns null when no rule applies
     * (direct bookings).
     */
    public function accrue(Reservation $reservation, ?User $by = null): ?CommissionAccrual
    {
        $source = trim((string) $reservation->source);

        if ($source === '' || $source === 'direct') {
            return null;
        }

        $rule = CommissionRule::forBranch($reservation->branch_id)
            ->where('source', $source)
            ->active()
            ->first();

        if (! $rule) {
            return null;
        }

        return DB::transaction(function () use ($reservation, $rule, $source, $by) {
            $base = $this->baseFor($reservation, $rule);
            $amount = RateEngine::mulDiv($base, $rule->rate_bps);

            $accrual = CommissionAccrual::firstOrCreate(
                ['idempotency_key' => "commission.{$reservation->id}"],
                [
                    'branch_id' => $reservation->branch_id,
                    'reservation_id' => $reservation->id,
                    'source' => $source,
                    'base_minor' => $base,
                    'amount_minor' => $amount,
                    'status' => CommissionAccrual::STATUS_ACCRUED,
                ],
            );

            if ($accrual->wasRecentlyCreated && $amount > 0) {
                $branch = Branch::findOrFail($reservation->branch_id);
                $businessDate = (new BusinessDateService)->current($branch)->business_date->toDateString();

                (new PostingService(new JournalService))->post(
                    branch: $branch,
                    businessDate: $businessDate,
                    event: 'commission.accrued',
                    amountMinor: $amount,
                    source: $accrual,
                    idempotency: ['scope' => 'commission.accrue', 'key' => "commission.{$reservation->id}"],
                    createdBy: $by,
                );

                event(new CommissionAccrued($accrual));
            }

            return $accrual;
        });
    }

    /**
     * Commission base from the frozen snapshot: room-category nightly
     * lines for net_room, the stay total for gross. Falls back to the
     * booked room rate when no snapshot exists (legacy bookings).
     */
    public function baseFor(Reservation $reservation, CommissionRule $rule): int
    {
        if ($rule->base === CommissionRule::BASE_GROSS) {
            return $reservation->total_amount;
        }

        $snapshot = $reservation->rate_snapshot;
        $nights = is_array($snapshot) ? ($snapshot['nights'] ?? null) : null;

        if (is_array($nights)) {
            $room = 0;
            foreach ($nights as $night) {
                if (! is_array($night)) {
                    continue;
                }
                $components = $night['components'] ?? null;
                if (! is_array($components)) {
                    continue;
                }
                foreach ($components as $component) {
                    if (is_array($component) && ($component['category'] ?? null) === 'room_rate') {
                        $value = $component['amount_minor'] ?? 0;
                        $room += is_int($value) ? $value : 0;
                    }
                }
            }

            return $room;
        }

        $nightsCount = max(1, (int) Carbon::parse($reservation->check_in_date->toDateString())
            ->diffInDays(Carbon::parse($reservation->check_out_date->toDateString())));

        return $reservation->room_rate * $nightsCount;
    }

    /**
     * Build a payout over whole accrual lines. An explicit amount must
     * equal the linked sum (over/under-payout → 422); omitted amount
     * pays the lines in full. Lines flip to invoiced.
     *
     * @param  list<int>  $accrualIds
     */
    public function createPayout(Branch $branch, string $source, array $accrualIds, ?int $amountMinor, ?string $reference, ?User $by): CommissionPayout
    {
        if ($accrualIds === []) {
            throw new AvailabilityException('PAYOUT_EMPTY', 'A payout needs at least one accrual.');
        }

        return DB::transaction(function () use ($branch, $source, $accrualIds, $amountMinor, $reference) {
            $accruals = CommissionAccrual::where('branch_id', $branch->id)
                ->where('source', $source)
                ->whereIn('id', $accrualIds)
                ->lockForUpdate()
                ->get();

            if ($accruals->count() !== count($accrualIds)) {
                throw new AvailabilityException('PAYOUT_SCOPE', 'Accruals must belong to this property and source.');
            }

            foreach ($accruals as $accrual) {
                if (! $accrual->payable()) {
                    throw new AvailabilityException('PAYOUT_LINE_LOCKED', "Accrual {$accrual->id} is {$accrual->status} and cannot be paid.");
                }
            }

            $total = $accruals->sum('amount_minor');
            $sum = is_numeric($total) ? (int) $total : 0;

            if ($amountMinor !== null && $amountMinor !== $sum) {
                throw new AvailabilityException('PAYOUT_MISMATCH', "Payout amount {$amountMinor} does not match the linked sum {$sum}.");
            }

            $payout = CommissionPayout::create([
                'branch_id' => $branch->id,
                'source' => $source,
                'amount_minor' => $sum,
                'status' => CommissionPayout::STATUS_PENDING,
                'reference' => $reference,
            ]);

            $payout->accruals()->sync($accruals->map->id->all());

            CommissionAccrual::whereIn('id', $accruals->map->id->all())
                ->update(['status' => CommissionAccrual::STATUS_INVOICED]);

            return $payout;
        });
    }

    /**
     * Pay a pending payout: clears the payable in the journal and marks
     * every line paid.
     */
    public function payPayout(CommissionPayout $payout, ?User $by = null): CommissionPayout
    {
        return DB::transaction(function () use ($payout, $by) {
            $locked = CommissionPayout::where('id', $payout->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== CommissionPayout::STATUS_PENDING) {
                throw new AvailabilityException('PAYOUT_STATE', 'Only pending payouts can be paid.');
            }

            $branch = Branch::findOrFail($locked->branch_id);
            $businessDate = (new BusinessDateService)->current($branch)->business_date->toDateString();

            (new PostingService(new JournalService))->post(
                branch: $branch,
                businessDate: $businessDate,
                event: 'commission.paid',
                amountMinor: $locked->amount_minor,
                source: $locked,
                idempotency: ['scope' => 'commission.pay', 'key' => "payout.{$locked->id}"],
                createdBy: $by,
            );

            $locked->accruals()->update(['status' => CommissionAccrual::STATUS_PAID]);
            $locked->update(['status' => CommissionPayout::STATUS_PAID]);

            event(new PayoutCompleted($locked->fresh() ?? $locked));

            return $locked->fresh() ?? $locked;
        });
    }

    public function dispute(CommissionAccrual $accrual): CommissionAccrual
    {
        return DB::transaction(function () use ($accrual) {
            $locked = CommissionAccrual::where('id', $accrual->id)->lockForUpdate()->firstOrFail();

            if (! $locked->payable()) {
                throw new AvailabilityException('ACCRUAL_LOCKED', "Accrual {$locked->id} is {$locked->status} and cannot be disputed.");
            }

            $locked->update(['status' => CommissionAccrual::STATUS_DISPUTED]);

            return $locked->fresh() ?? $locked;
        });
    }

    public function resolveDispute(CommissionAccrual $accrual): CommissionAccrual
    {
        return DB::transaction(function () use ($accrual) {
            $locked = CommissionAccrual::where('id', $accrual->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== CommissionAccrual::STATUS_DISPUTED) {
                throw new AvailabilityException('ACCRUAL_STATE', 'Only disputed accruals can be resolved.');
            }

            $locked->update(['status' => CommissionAccrual::STATUS_ACCRUED]);

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * Accrue for checked-out stays in the last N days (source≠direct).
     * Idempotent: already-accrued stays return their original rows.
     *
     * @return array{accrued: int, skipped: int}
     */
    public function backfill(Branch $branch, int $days = 90): array
    {
        $accrued = 0;
        $skipped = 0;

        Reservation::forBranch($branch->id)
            ->checkedOut()
            ->where('source', '!=', 'direct')
            ->where('check_out_date', '>=', Carbon::today()->subDays($days)->toDateString())
            ->orderBy('id')
            ->chunkById(100, function ($stays) use (&$accrued, &$skipped) {
                foreach ($stays as $stay) {
                    if ($this->accrue($stay)) {
                        $accrued++;
                    } else {
                        $skipped++;
                    }
                }
            });

        return ['accrued' => $accrued, 'skipped' => $skipped];
    }
}
