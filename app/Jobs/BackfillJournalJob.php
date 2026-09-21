<?php

namespace App\Jobs;

use App\Models\Branch;
use App\Models\PaymentTransaction;
use App\Models\Transaction;
use App\Services\BusinessDateService;
use App\Services\JournalService;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * One-shot replay of legacy postings into the append-only journal.
 *
 * Idempotent: every entry carries scope/key (journal.backfill:{table}:{id}),
 * so re-runs insert zero rows. Originals are never modified.
 */
class BackfillJournalJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct()
    {
        $this->onQueue('maintenance');
    }

    /**
     * @return array{entries: int}
     */
    public function handle(JournalService $journal, BusinessDateService $businessDates): array
    {
        $count = 0;

        Transaction::query()
            ->where('is_voided', false)
            ->orderBy('id')
            ->chunkById(500, function ($transactions) use ($journal, $businessDates, &$count) {
                foreach ($transactions as $tx) {
                    $branchId = DB::table('folios')->where('id', $tx->folio_id)->value('branch_id');

                    if (! is_int($branchId)) {
                        continue;
                    }

                    $branch = Branch::find($branchId);

                    if ($branch === null) {
                        continue;
                    }

                    $businessDate = $tx->business_date instanceof CarbonInterface
                        ? $tx->business_date->toDateString()
                        : $businessDates->current($branch)->business_date->toDateString();

                    $journal->post(
                        branch: $branch,
                        businessDate: $businessDate,
                        event: $tx->type === 'credit' ? 'payment.received' : 'charge.posted',
                        debitAccount: $tx->type === 'credit' ? 'CASH' : 'GUEST_LEDGER',
                        creditAccount: $tx->type === 'credit' ? 'GUEST_LEDGER' : 'REVENUE',
                        amountMinor: $tx->amount,
                        source: $tx,
                        idempotency: ['scope' => 'journal.backfill', 'key' => "transactions:{$tx->id}"],
                    );

                    $count++;
                }
            });

        PaymentTransaction::query()
            ->where('status', 'success')
            ->orderBy('id')
            ->chunkById(500, function ($payments) use ($journal, $businessDates, &$count) {
                foreach ($payments as $payment) {
                    $branch = $payment->branch;

                    $businessDate = $payment->business_date instanceof CarbonInterface
                        ? $payment->business_date->toDateString()
                        : $businessDates->current($branch)->business_date->toDateString();

                    $journal->post(
                        branch: $branch,
                        businessDate: $businessDate,
                        event: 'payment.received',
                        debitAccount: 'CASH',
                        creditAccount: 'GUEST_LEDGER',
                        amountMinor: $payment->amount,
                        source: $payment,
                        idempotency: ['scope' => 'journal.backfill', 'key' => "payment_transactions:{$payment->id}"],
                    );

                    $count++;
                }
            });

        return ['entries' => $count];
    }
}
