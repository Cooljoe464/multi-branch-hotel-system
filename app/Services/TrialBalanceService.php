<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\TrialBalance;

/**
 * Daily trial balance over the journal. Balanced means total debits equal
 * total credits AND every referenced account exists in the chart. Computed
 * rows are idempotent (updateOrCreate); the night audit treats an
 * unbalanced day as a blocking failure.
 */
class TrialBalanceService
{
    /**
     * @return array{balanced: bool, debits: int, credits: int, unknown_accounts: list<string>, per_account: array<string, array{debit: int, credit: int}>}
     */
    public function totals(Branch $branch, string $businessDate): array
    {
        $entries = JournalEntry::forBranch($branch->id)->forBusinessDate($businessDate)->get();

        $perAccount = [];
        $debits = 0;
        $credits = 0;

        foreach ($entries as $entry) {
            $debits += $entry->amount_minor;
            $credits += $entry->amount_minor;

            foreach (['debit' => $entry->debit_account, 'credit' => $entry->credit_account] as $side => $code) {
                $perAccount[$code] ??= ['debit' => 0, 'credit' => 0];
                $perAccount[$code][$side] += $entry->amount_minor;
            }
        }

        $known = array_values(array_filter(
            ChartAccount::pluck('code')->all(),
            fn ($code) => is_string($code)
        ));
        $unknown = array_values(array_diff(array_keys($perAccount), $known));

        return [
            'balanced' => $debits === $credits && $unknown === [],
            'debits' => $debits,
            'credits' => $credits,
            'unknown_accounts' => $unknown,
            'per_account' => $perAccount,
        ];
    }

    public function close(Branch $branch, string $businessDate): TrialBalance
    {
        $totals = $this->totals($branch, $businessDate);

        return TrialBalance::updateOrCreate(
            ['branch_id' => $branch->id, 'business_date' => $businessDate],
            ['totals' => $totals, 'balanced' => $totals['balanced']],
        );
    }
}
