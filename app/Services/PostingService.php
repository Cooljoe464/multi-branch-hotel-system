<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\JournalEntry;
use App\Models\PostingRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Validated facade over the append-only journal. Every posting resolves
 * its event through posting_rules and asserts both legs exist in the
 * chart of accounts. Runs inside the caller's transaction.
 */
class PostingService
{
    public function __construct(private JournalService $journal) {}

    /**
     * @param  array{scope: string, key: string}|null  $idempotency
     */
    public function post(
        Branch $branch,
        string $businessDate,
        string $event,
        int $amountMinor,
        ?Model $source = null,
        ?array $idempotency = null,
        ?User $createdBy = null,
    ): JournalEntry {
        $rule = PostingRule::where('event', $event)->first();

        if (! $rule) {
            throw new RuntimeException("No posting rule for event [{$event}].");
        }

        foreach ([$rule->debit_account, $rule->credit_account] as $code) {
            if (! ChartAccount::where('code', $code)->exists()) {
                throw new RuntimeException("Chart account [{$code}] does not exist for event [{$event}].");
            }
        }

        return $this->journal->post(
            branch: $branch,
            businessDate: $businessDate,
            event: $event,
            debitAccount: $rule->debit_account,
            creditAccount: $rule->credit_account,
            amountMinor: $amountMinor,
            source: $source,
            idempotency: $idempotency,
            createdBy: $createdBy,
        );
    }
}
