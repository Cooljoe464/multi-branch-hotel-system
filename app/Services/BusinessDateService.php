<?php

namespace App\Services;

use App\Events\BusinessDateAdvanced;
use App\Exceptions\BusinessDateAlreadyClosingException;
use App\Models\Branch;
use App\Models\BusinessDate;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Single source of truth for "what day is it" at a branch.
 *
 * All revenue postings must anchor to BusinessDateService::current()
 * instead of now()/whereDate(created_at). Time is always evaluated in
 * the branch timezone.
 */
class BusinessDateService
{
    /**
     * Today's date in the branch timezone (Y-m-d).
     */
    public function todayFor(Branch $branch): string
    {
        return Carbon::now($branch->timezone ?? 'Africa/Lagos')->toDateString();
    }

    /**
     * Return the open business date, creating today's row if none exists.
     */
    public function current(Branch $branch): BusinessDate
    {
        return DB::transaction(function () use ($branch) {
            $this->acquireBranchLock($branch->id);

            $open = BusinessDate::forBranch($branch->id)->open()->lockForUpdate()->first();

            if ($open) {
                return $open;
            }

            return $this->openDate($branch, $this->todayFor($branch), null);
        });
    }

    /**
     * Fail unless an open business date exists (does not create one).
     */
    public function requireOpen(Branch $branch): BusinessDate
    {
        $open = BusinessDate::forBranch($branch->id)->open()->first();

        if (! $open) {
            throw new LogicException("No open business date for branch {$branch->id}.");
        }

        return $open;
    }

    /**
     * Close the current business date and open the next day.
     *
     * Safe to call concurrently: the open row is locked, so a second
     * caller gets BusinessDateAlreadyClosingException. Crash recovery:
     * a row left in "closing" state is resumed, never duplicated.
     */
    public function advance(Branch $branch, ?User $closedBy = null): BusinessDate
    {
        return DB::transaction(function () use ($branch, $closedBy) {
            $this->acquireBranchLock($branch->id);

            $open = BusinessDate::forBranch($branch->id)->open()->lockForUpdate()->first();

            if (! $open) {
                $resumed = $this->resumeClosing($branch, $closedBy);

                if ($resumed) {
                    return $resumed;
                }

                throw new BusinessDateAlreadyClosingException(
                    "No open business date to advance for branch {$branch->id}."
                );
            }

            $open->update([
                'status' => BusinessDate::STATUS_CLOSING,
                'closed_by' => $closedBy?->id,
            ]);

            return $this->finishAdvance($branch, $open, $closedBy);
        });
    }

    /**
     * Complete a close left in "closing" state by an interrupted advance().
     * Returns the next open date, or null when nothing needs resuming.
     */
    public function resumeClosing(Branch $branch, ?User $closedBy = null): ?BusinessDate
    {
        return DB::transaction(function () use ($branch, $closedBy) {
            $this->acquireBranchLock($branch->id);

            $closing = BusinessDate::forBranch($branch->id)
                ->where('status', BusinessDate::STATUS_CLOSING)
                ->lockForUpdate()
                ->first();

            if (! $closing) {
                return null;
            }

            return $this->finishAdvance($branch, $closing, $closedBy);
        });
    }

    private function finishAdvance(Branch $branch, BusinessDate $closing, ?User $closedBy): BusinessDate
    {
        $nextDate = Carbon::parse($closing->business_date)->addDay()->toDateString();

        $next = BusinessDate::forBranch($branch->id)
            ->where('business_date', $nextDate)
            ->lockForUpdate()
            ->first();

        if (! $next) {
            $next = $this->openDate($branch, $nextDate, $closedBy);
        }

        $closing->update([
            'status' => BusinessDate::STATUS_CLOSED,
            'closed_at' => now(),
            'closed_by' => $closedBy !== null ? $closedBy->id : $closing->closed_by,
        ]);

        $branch->update(['current_business_date' => $next->business_date]);

        event(new BusinessDateAdvanced($branch, $closing, $next));

        return $next;
    }

    private function openDate(Branch $branch, string $date, ?User $openedBy): BusinessDate
    {
        $row = BusinessDate::create([
            'branch_id' => $branch->id,
            'business_date' => $date,
            'status' => BusinessDate::STATUS_OPEN,
            'opened_at' => now(),
            'opened_by' => $openedBy?->id,
        ]);

        $branch->update(['current_business_date' => $row->business_date]);

        return $row;
    }

    /**
     * Serialize concurrent claimants per branch. Advisory locks are
     * PostgreSQL-only; other drivers rely on the row-level lock above.
     */
    private function acquireBranchLock(int $branchId): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ["bizdate:{$branchId}"]);
        }
    }
}
