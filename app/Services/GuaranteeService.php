<?php

namespace App\Services;

use App\Events\DepositOverdue;
use App\Events\HoldReleased;
use App\Events\NoShowPenaltyPosted;
use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Models\Folio;
use App\Models\GuaranteePolicy;
use App\Models\Reservation;
use App\Models\Transaction;
use App\Models\User;
use App\Support\BranchTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Deposits, guarantee holds, cancellation/no-show penalties and
 * automatic hold release. All money in integer minor units; penalty
 * math derives from frozen reservation amounts, never live rates.
 */
class GuaranteeService
{
    /**
     * Policy for a plan, falling back to the branch default. Latest
     * wins so a new plan-specific policy overrides the default.
     */
    public function policyFor(int $branchId, ?int $ratePlanId): ?GuaranteePolicy
    {
        if ($ratePlanId !== null) {
            $specific = GuaranteePolicy::forBranch($branchId)->active()
                ->where('rate_plan_id', $ratePlanId)
                ->orderByDesc('id')
                ->first();

            if ($specific) {
                return $specific;
            }
        }

        return GuaranteePolicy::forBranch($branchId)->active()
            ->whereNull('rate_plan_id')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Stamp deposit + deadlines on a fresh booking. No policy (or a
     * policy-free plan) leaves the reservation unguaranteed ('none').
     */
    public function applyOnReserve(Reservation $reservation): void
    {
        $policy = $this->policyFor($reservation->branch_id, $reservation->rate_plan_id);

        if (! $policy) {
            return;
        }

        $depositDue = $this->depositDue($policy, $reservation->total_amount);
        $branch = Branch::findOrFail($reservation->branch_id);

        $reservation->update([
            'guarantee_status' => $this->initialStatus($policy, $depositDue),
            'deposit_due_minor' => $depositDue,
            'deposit_paid_minor' => 0,
            'cancel_deadline_at' => BranchTime::parse($branch, $reservation->check_in_date->toDateString())
                ->subHours($policy->ruleInt('cancel_free_until_hours', 24)),
            'hold_expires_at' => BranchTime::now($branch)->addHours($policy->ruleInt('hold_hours', 24)),
            'metadata' => array_merge($reservation->metadata ?? [], [
                'guarantee_policy_id' => $policy->id,
                'guarantee_kind' => $policy->kind,
            ]),
        ]);
    }

    /**
     * Collect a deposit against the folio (Front Desk) and flip the
     * hold to guaranteed once the schedule is met.
     */
    public function collectDeposit(Reservation $reservation, int $amountMinor, ?User $collectedBy, string $method = 'card', ?string $reference = null): Transaction
    {
        if ($amountMinor <= 0) {
            throw new AvailabilityException('DEPOSIT_INVALID', 'Deposit amount must be positive.');
        }

        return DB::transaction(function () use ($reservation, $amountMinor, $collectedBy, $method, $reference) {
            $locked = Reservation::where('id', $reservation->id)->lockForUpdate()->firstOrFail();

            $folio = Folio::where('reservation_id', $locked->id)->first()
                ?? (new FolioService)->createFolio($locked->branch_id, $locked->id, null, "Guest Folio: {$locked->guest_name}");

            $description = "Deposit ({$method})";
            if ($reference) {
                $description .= " ({$reference})";
            }

            $transaction = (new FolioService)->postCredit(
                $folio,
                'deposit',
                $description,
                $amountMinor,
                $collectedBy?->id,
                referenceType: Reservation::class,
                referenceId: $locked->id,
                journalEvent: 'deposit.received',
            );

            $this->syncDepositFromFolio($locked->fresh() ?? $locked);

            return $transaction;
        });
    }

    /**
     * Recompute paid-to-date from folio deposit credits; guarantees the
     * hold once the schedule is met. Returns the paid total.
     */
    public function syncDepositFromFolio(Reservation $reservation): int
    {
        $paid = (int) Transaction::where('type', 'credit')
            ->where('category', 'deposit')
            ->where('is_voided', false)
            ->whereHas('folio', fn ($q) => $q->where('reservation_id', $reservation->id))
            ->sum('amount');

        $updates = ['deposit_paid_minor' => $paid];

        if ($paid >= $reservation->deposit_due_minor && $reservation->deposit_due_minor > 0
            && $reservation->guarantee_status === 'hold') {
            $updates['guarantee_status'] = 'guaranteed';
        }

        $reservation->update($updates);

        return $paid;
    }

    /**
     * Guest/agent cancellation with deadline-aware fees. $waive must be
     * pre-authorized by the caller (reservations.waive_penalty).
     *
     * @return array{fee_minor: int, waived: bool}
     */
    public function cancelReservation(Reservation $reservation, ?User $cancelledBy, bool $waive = false): array
    {
        return DB::transaction(function () use ($reservation, $cancelledBy, $waive) {
            $locked = Reservation::where('id', $reservation->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'cancelled') {
                return ['fee_minor' => 0, 'waived' => false];
            }

            if (in_array($locked->status, ['checked_in', 'checked_out', 'no_show'], true)) {
                throw new AvailabilityException('CANCEL_INVALID', 'This reservation cannot be cancelled.');
            }

            $fee = $this->cancellationFee($locked);

            if ($waive) {
                $fee = 0;
            }

            if ($fee > 0) {
                $this->postFee($locked, 'cancellation_fee', "Cancellation fee: {$locked->confirmation_number}", $fee, $cancelledBy?->id, 'cancellation.fee');
            }

            (new AvailabilityService)->release($locked);

            $locked->update([
                'status' => 'cancelled',
                'guarantee_status' => $fee > 0 ? 'forfeited' : $locked->guarantee_status,
            ]);

            if ($locked->room && $locked->room->status === 'reserved') {
                $locked->room->update(['status' => 'available']);
            }

            event(new HoldReleased($locked->fresh() ?? $locked, 'cancelled'));

            return ['fee_minor' => $fee, 'waived' => $waive && $this->cancellationFee($locked) > 0];
        });
    }

    /**
     * No-show penalty (called by the night-audit no-show step).
     * Crash-safe: a folio that already carries the fee for the date is
     * finalized without re-posting.
     */
    public function noShowPenalty(Reservation $reservation, string $date, ?User $runBy): int
    {
        return DB::transaction(function () use ($reservation, $date, $runBy) {
            $locked = Reservation::where('id', $reservation->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, ['pending', 'confirmed', 'reserved'], true)) {
                return 0;
            }

            $fee = $this->noShowFee($locked);

            $folio = Folio::where('reservation_id', $locked->id)->first()
                ?? (new FolioService)->createFolio($locked->branch_id, $locked->id, null, "Guest Folio: {$locked->guest_name}");

            $alreadyPosted = Transaction::where('folio_id', $folio->id)
                ->where('category', 'no_show_fee')
                ->where('business_date', $date)
                ->where('is_voided', false)
                ->exists();

            if (! $alreadyPosted && $fee > 0) {
                (new FolioService)->postDebit(
                    $folio,
                    'no_show_fee',
                    "No-show fee: {$date}",
                    $fee,
                    $runBy?->id,
                    journalEvent: 'no_show.fee',
                );
            }

            (new AvailabilityService)->release($locked);

            $locked->update([
                'status' => 'no_show',
                'audit_outcome' => 'no_show',
                'no_show_fee_minor' => $fee,
                'guarantee_status' => 'forfeited',
            ]);

            event(new NoShowPenaltyPosted($locked->fresh() ?? $locked, $fee));

            return $fee;
        });
    }

    /**
     * Cancel expired holds and flag overdue deposits. Re-runs are
     * no-ops: released holds are already cancelled.
     *
     * @return array{released: int, overdue_flagged: int}
     */
    public function releaseExpiredHolds(): array
    {
        $released = 0;
        $overdue = 0;

        // Expiry compares against each hold's own branch clock; the DB
        // pass only narrows to live holds, never to a timestamp.
        Reservation::where('guarantee_status', 'hold')
            ->whereIn('status', ['pending', 'confirmed', 'reserved'])
            ->with('branch')
            ->orderBy('id')
            ->chunkById(100, function ($holds) use (&$released) {
                foreach ($holds as $hold) {
                    /** @var Reservation $hold */
                    if ($hold->hold_expires_at === null) {
                        continue;
                    }

                    if (BranchTime::now($hold->branch)->greaterThan($hold->hold_expires_at)) {
                        $this->cancelReservation($hold, null);
                        $released++;
                    }
                }
            });

        Reservation::where('guarantee_status', 'hold')
            ->where('deposit_due_minor', '>', 0)
            ->whereColumn('deposit_paid_minor', '<', 'deposit_due_minor')
            ->whereIn('status', ['pending', 'confirmed', 'reserved'])
            ->orderBy('id')
            ->chunkById(100, function ($holds) use (&$overdue) {
                foreach ($holds as $hold) {
                    /** @var Reservation $hold */
                    if ($this->depositDueAt($hold)?->isPast() && ! ($hold->metadata['deposit_overdue_fired'] ?? false)) {
                        $hold->update(['metadata' => array_merge($hold->metadata ?? [], ['deposit_overdue_fired' => true])]);
                        event(new DepositOverdue($hold));
                        $overdue++;
                    }
                }
            });

        return ['released' => $released, 'overdue_flagged' => $overdue];
    }

    /**
     * Deposit schedule for a stay total.
     */
    public function depositDue(GuaranteePolicy $policy, int $totalMinor): int
    {
        return RateEngine::mulDiv($totalMinor, $policy->ruleInt('deposit_bps', 0));
    }

    public function depositDueAt(Reservation $reservation): ?Carbon
    {
        $policyId = is_array($reservation->metadata) ? ($reservation->metadata['guarantee_policy_id'] ?? null) : null;

        if (! is_int($policyId)) {
            return null;
        }

        $policy = GuaranteePolicy::find($policyId);

        if (! $policy) {
            return null;
        }

        $hours = $policy->ruleInt('due_hours_before_arrival', 0);

        if ($hours <= 0) {
            return null;
        }

        return BranchTime::parse(
            Branch::findOrFail($reservation->branch_id),
            $reservation->check_in_date->toDateString()
        )->subHours($hours);
    }

    private function initialStatus(GuaranteePolicy $policy, int $depositDue): string
    {
        if ($policy->kind === GuaranteePolicy::KIND_COMPANY) {
            return 'guaranteed';
        }

        return $depositDue > 0 ? 'hold' : 'guaranteed';
    }

    private function cancellationFee(Reservation $reservation): int
    {
        $policy = $this->policyFor($reservation->branch_id, $reservation->rate_plan_id);

        if (! $policy) {
            return 0;
        }

        if ($reservation->cancel_deadline_at && BranchTime::now(Branch::findOrFail($reservation->branch_id))->lessThanOrEqualTo($reservation->cancel_deadline_at)) {
            return 0;
        }

        return $this->policyFee($policy, $reservation);
    }

    private function noShowFee(Reservation $reservation): int
    {
        $policy = $this->policyFor($reservation->branch_id, $reservation->rate_plan_id);

        if ($policy) {
            return $this->policyFee($policy, $reservation);
        }

        return $reservation->no_show_fee_minor > 0 ? $reservation->no_show_fee_minor : $reservation->room_rate;
    }

    private function policyFee(GuaranteePolicy $policy, Reservation $reservation): int
    {
        $mode = $policy->ruleString('no_show_fee', 'first_night');

        if ($mode === 'percent') {
            return RateEngine::mulDiv($reservation->total_amount, $policy->ruleInt('no_show_fee_bps', 0));
        }

        return $reservation->room_rate;
    }

    private function postFee(Reservation $reservation, string $category, string $description, int $fee, ?int $postedBy, string $journalEvent): void
    {
        $folio = Folio::where('reservation_id', $reservation->id)->first()
            ?? (new FolioService)->createFolio($reservation->branch_id, $reservation->id, null, "Guest Folio: {$reservation->guest_name}");

        $alreadyPosted = Transaction::where('folio_id', $folio->id)
            ->where('category', $category)
            ->where('is_voided', false)
            ->exists();

        if ($alreadyPosted) {
            return;
        }

        (new FolioService)->postDebit(
            $folio,
            $category,
            $description,
            $fee,
            $postedBy,
            journalEvent: $journalEvent,
        );
    }
}
