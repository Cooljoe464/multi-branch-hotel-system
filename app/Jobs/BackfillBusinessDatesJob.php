<?php

namespace App\Jobs;

use App\Models\Branch;
use App\Models\BusinessDate;
use App\Services\BusinessDateService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * One-shot backfill for the business-date foundation.
 *
 * Idempotent: safe to re-run; every write is updateOrCreate or a
 * whereNull-targeted update, so re-runs change zero rows.
 *
 * - Ensures one open business_dates row (today, branch timezone).
 * - Ensures closed rows for every historic daily_ledgers date.
 * - Backfills business_date on posting tables from their timestamps
 *   (reservations anchor to check_in_date; postings to paid_at/created_at).
 */
class BackfillBusinessDatesJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    /**
     * @return array{branches: int, dates_opened: int, dates_closed: int, rows_stamped: int}
     */
    public function handle(BusinessDateService $businessDates): array
    {
        $stats = ['branches' => 0, 'dates_opened' => 0, 'dates_closed' => 0, 'rows_stamped' => 0];

        Branch::query()->chunkById(100, function ($branches) use ($businessDates, &$stats) {
            foreach ($branches as $branch) {
                $stats['branches']++;

                $open = $businessDates->current($branch);
                if ($open->wasRecentlyCreated) {
                    $stats['dates_opened']++;
                }

                $historicDates = DB::table('daily_ledgers')
                    ->where('branch_id', $branch->id)
                    ->where('business_date', '<', $open->business_date->toDateString())
                    ->distinct()
                    ->pluck('business_date');

                foreach ($historicDates as $date) {
                    $row = BusinessDate::updateOrCreate(
                        ['branch_id' => $branch->id, 'business_date' => $date],
                        ['status' => BusinessDate::STATUS_CLOSED, 'closed_at' => now()]
                    );

                    if ($row->wasRecentlyCreated) {
                        $stats['dates_closed']++;
                    }
                }

                $stats['rows_stamped'] += $this->stampPostingTables($branch->id);
            }
        });

        return $stats;
    }

    private function stampPostingTables(int $branchId): int
    {
        $stamped = 0;

        $stamped += DB::table('reservations')
            ->where('branch_id', $branchId)
            ->whereNull('business_date')
            ->update(['business_date' => DB::raw('check_in_date')]);

        $stamped += DB::table('transactions')
            ->join('folios', 'folios.id', '=', 'transactions.folio_id')
            ->where('folios.branch_id', $branchId)
            ->whereNull('transactions.business_date')
            ->update(['transactions.business_date' => DB::raw('DATE(transactions.created_at)')]);

        $stamped += DB::table('pos_charges')
            ->where('branch_id', $branchId)
            ->whereNull('business_date')
            ->update(['business_date' => DB::raw('DATE(created_at)')]);

        $stamped += DB::table('payment_transactions')
            ->where('branch_id', $branchId)
            ->whereNull('business_date')
            ->update(['business_date' => DB::raw('DATE(COALESCE(paid_at, created_at))')]);

        return $stamped;
    }
}
