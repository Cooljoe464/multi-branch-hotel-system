<?php

namespace App\Services;

use App\Events\JournalPosted;
use App\Models\Branch;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Append-only financial journal, separate from the activity log.
 *
 * Every posting runs inside the caller's transaction (this service never
 * commits on its own). Voids are compensating reversals, never UPDATEs.
 * All amounts are integer minor units.
 */
class JournalService
{
    /**
     * @param  array{scope: string, key: string}|null  $idempotency
     */
    public function post(
        Branch $branch,
        string $businessDate,
        string $event,
        string $debitAccount,
        string $creditAccount,
        int $amountMinor,
        ?Model $source = null,
        ?array $idempotency = null,
        ?User $createdBy = null,
    ): JournalEntry {
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Journal amount must be a positive integer of minor units.');
        }

        if ($idempotency !== null) {
            $existing = JournalEntry::where('idempotency_scope', $idempotency['scope'])
                ->where('idempotency_key', $idempotency['key'])
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        $entry = JournalEntry::create([
            'branch_id' => $branch->id,
            'business_date' => $businessDate,
            'event' => $event,
            'debit_account' => $debitAccount,
            'credit_account' => $creditAccount,
            'amount_minor' => $amountMinor,
            'currency_code' => $branch->currency_code ?? 'NGN',
            'fx_rate_to_branch_minor' => 1000000,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'idempotency_scope' => $idempotency['scope'] ?? null,
            'idempotency_key' => $idempotency['key'] ?? null,
            'created_by' => $createdBy?->id,
            'posted_at' => now(),
        ]);

        event(new JournalPosted($entry));

        return $entry;
    }

    /**
     * Post the compensating reversal of an earlier entry.
     */
    public function reverse(
        JournalEntry $original,
        string $event,
        ?User $createdBy = null,
    ): JournalEntry {
        $branch = $original->branch;

        return $this->post(
            branch: $branch,
            businessDate: app(BusinessDateService::class)->current($branch)->business_date->toDateString(),
            event: $event,
            debitAccount: $original->credit_account,
            creditAccount: $original->debit_account,
            amountMinor: $original->amount_minor,
            source: $original->source,
            createdBy: $createdBy,
        );
    }
}
