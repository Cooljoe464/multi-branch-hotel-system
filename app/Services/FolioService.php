<?php

namespace App\Services;

use App\Events\DisputeInitiated;
use App\Events\DisputeResolved;
use App\Events\FolioClosed;
use App\Events\FolioTransferred;
use App\Events\PaymentReceived;
use App\Events\TransactionPosted;
use App\Exceptions\StaleModelException;
use App\Models\Branch;
use App\Models\CashierShift;
use App\Models\Folio;
use App\Models\FolioDispute;
use App\Models\FolioRoutingRule;
use App\Models\FolioWindow;
use App\Models\PosCharge;
use App\Models\Reservation;
use App\Models\TaxProfile;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class FolioService
{
    private function resolveCurrencyCode(int $branchId): string
    {
        $branch = Branch::find($branchId);

        if ($branch === null) {
            return 'NGN';
        }

        return $branch->currency_code;
    }

    public function createFolio(int $branchId, ?int $reservationId = null, ?int $parentFolioId = null, ?string $description = null): Folio
    {
        $type = $parentFolioId ? Folio::TYPE_CHILD : ($reservationId ? Folio::TYPE_INDIVIDUAL : Folio::TYPE_MASTER);

        return Folio::create([
            'branch_id' => $branchId,
            'currency_code' => $this->resolveCurrencyCode($branchId),
            'reservation_id' => $reservationId,
            'parent_folio_id' => $parentFolioId,
            'type' => $type,
            'status' => 'open',
            'description' => $description,
        ]);
    }

    public function createStaffFolio(int $branchId, string $guestName, ?string $description = null): Folio
    {
        return Folio::create([
            'branch_id' => $branchId,
            'currency_code' => $this->resolveCurrencyCode($branchId),
            'type' => Folio::TYPE_STAFF,
            'status' => 'open',
            'guest_name' => $guestName,
            'description' => $description,
        ]);
    }

    public function createNonGuestFolio(int $branchId, string $guestName, ?string $description = null): Folio
    {
        return Folio::create([
            'branch_id' => $branchId,
            'currency_code' => $this->resolveCurrencyCode($branchId),
            'type' => Folio::TYPE_NON_GUEST,
            'status' => 'open',
            'guest_name' => $guestName,
            'description' => $description,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $metadata  Stored on the transaction (e.g. night-audit rate markers).
     */
    public function postDebit(Folio $folio, string $category, string $description, int $amount, ?int $postedBy = null, ?int $taxRateBps = null, ?string $referenceType = null, ?int $referenceId = null, ?int $expectedVersion = null, ?int $taxProfileId = null, ?string $windowCode = null, ?string $journalEvent = null, ?array $metadata = null): Transaction
    {
        return DB::transaction(function () use ($folio, $category, $description, $amount, $postedBy, $taxRateBps, $referenceType, $referenceId, $expectedVersion, $taxProfileId, $windowCode, $journalEvent, $metadata) {
            $lockedFolio = Folio::where('id', $folio->id)->lockForUpdate()->first();

            if (! $lockedFolio) {
                throw new \RuntimeException('Folio not found during postDebit.');
            }

            if ($expectedVersion !== null && $lockedFolio->version !== $expectedVersion) {
                throw new StaleModelException(Folio::class, $lockedFolio->id, $expectedVersion);
            }

            $taxAmount = 0;
            if ($taxRateBps !== null && $taxRateBps > 0) {
                $taxAmount = (int) round($amount * $taxRateBps / 10000);
            }

            $taxSnapshot = ['version' => 'legacy', 'rate_bps' => $taxRateBps];

            if ($taxProfileId !== null) {
                $computed = $this->taxForProfile($lockedFolio, $taxProfileId, $category, $amount);
                $taxAmount = $computed['total_minor'];
                $taxSnapshot = $computed['snapshot'];
            }

            $transaction = Transaction::create([
                'folio_id' => $folio->id,
                'folio_window_id' => $this->resolveWindow($lockedFolio, $category, $windowCode)->id,
                'cashier_shift_id' => $this->openShiftId($postedBy, (int) $lockedFolio->branch_id),
                'business_date' => (new BusinessDateService)->current($lockedFolio->branch)->business_date->toDateString(),
                'currency_code' => $folio->currency_code,
                'type' => 'debit',
                'category' => $category,
                'description' => $description,
                'amount' => $amount,
                'posted_by' => $postedBy,
                'is_taxable' => $taxRateBps !== null || $taxProfileId !== null,
                'tax_amount' => $taxAmount,
                'tax_snapshot' => $taxSnapshot,
                'tax_total_minor' => $taxAmount,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'metadata' => $metadata,
            ]);

            $lockedFolio->update([
                'balance' => $lockedFolio->outstanding_balance,
                'version' => $lockedFolio->version + 1,
            ]);

            $this->journalize($lockedFolio, $transaction, $journalEvent ?? 'charge.posted', $postedBy);

            event(new TransactionPosted($lockedFolio, $transaction));

            return $transaction;
        });
    }

    /**
     * @param  string|null  $journalEvent  Defaults to payment.received; deposits journal as deposit.received.
     */
    public function postCredit(Folio $folio, string $category, string $description, int $amount, ?int $postedBy = null, ?string $referenceType = null, ?int $referenceId = null, ?int $expectedVersion = null, ?string $windowCode = null, ?string $journalEvent = null): Transaction
    {
        return DB::transaction(function () use ($folio, $category, $description, $amount, $postedBy, $referenceType, $referenceId, $expectedVersion, $windowCode, $journalEvent) {
            $lockedFolio = Folio::where('id', $folio->id)->lockForUpdate()->first();

            if (! $lockedFolio) {
                throw new \RuntimeException('Folio not found during postCredit.');
            }

            if ($expectedVersion !== null && $lockedFolio->version !== $expectedVersion) {
                throw new StaleModelException(Folio::class, $lockedFolio->id, $expectedVersion);
            }

            $transaction = Transaction::create([
                'folio_id' => $folio->id,
                'folio_window_id' => $this->resolveWindow($lockedFolio, $category, $windowCode)->id,
                'cashier_shift_id' => $this->openShiftId($postedBy, (int) $lockedFolio->branch_id),
                'business_date' => (new BusinessDateService)->current($lockedFolio->branch)->business_date->toDateString(),
                'currency_code' => $folio->currency_code,
                'type' => 'credit',
                'category' => $category,
                'description' => $description,
                'amount' => $amount,
                'posted_by' => $postedBy,
                'is_taxable' => false,
                'tax_amount' => 0,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);

            $lockedFolio->update([
                'balance' => $lockedFolio->outstanding_balance,
                'version' => $lockedFolio->version + 1,
            ]);

            $this->journalize($lockedFolio, $transaction, $journalEvent ?? 'payment.received', $postedBy);

            event(new TransactionPosted($lockedFolio, $transaction));

            return $transaction;
        });
    }

    public function postManualCharge(Folio $folio, string $category, string $description, int $amount, ?int $taxRateBps = null, ?int $postedBy = null, ?int $expectedVersion = null, ?int $taxProfileId = null, ?string $windowCode = null): Transaction
    {
        return $this->postDebit(
            $folio,
            $category,
            $description,
            $amount,
            $postedBy,
            $taxRateBps,
            null,
            null,
            $expectedVersion,
            $taxProfileId,
            $windowCode,
        );
    }

    public function recordPayment(Folio $folio, int $amount, string $method, ?int $postedBy = null, ?string $reference = null): Transaction
    {
        $description = match ($method) {
            'cash' => 'Cash payment received',
            'card' => 'Card payment received',
            'online' => 'Online payment received',
            default => 'Payment received',
        };

        if ($reference) {
            $description .= " ({$reference})";
        }

        $transaction = $this->postCredit(
            $folio,
            'payment',
            $description,
            $amount,
            $postedBy,
        );

        event(new PaymentReceived($folio, $transaction));

        if ($folio->reservation) {
            $folio->reservation->syncPaymentStatus();
        }

        return $transaction;
    }

    public function checkout(Folio $folio, ?int $reservationId = null): void
    {
        DB::transaction(function () use ($folio, $reservationId) {
            $lockedFolio = Folio::where('id', $folio->id)->lockForUpdate()->first();

            if (! $lockedFolio) {
                throw new \RuntimeException('Folio not found during checkout.');
            }

            $balance = $this->getBalance($lockedFolio);

            if ($balance > 0) {
                throw new \LogicException("Cannot checkout folio with outstanding balance of {$balance} cents. Please collect payment first.");
            }

            $lockedFolio->update([
                'status' => 'closed',
                'is_settled' => true,
                'closed_at' => now(),
                'version' => $lockedFolio->version + 1,
            ]);

            event(new FolioClosed($lockedFolio));

            if ($reservationId) {
                $reservation = $lockedFolio->reservation;
                if ($reservation && $reservation->id === $reservationId) {
                    $reservation->update(['status' => 'checked_out']);
                }
            }
        });
    }

    public function transferCharge(Transaction $transaction, Folio $targetFolio): Transaction
    {
        if ($transaction->is_voided) {
            throw new \InvalidArgumentException('Cannot transfer a voided transaction.');
        }

        return DB::transaction(function () use ($transaction, $targetFolio) {
            $lockedTarget = Folio::where('id', $targetFolio->id)->lockForUpdate()->first();

            if (! $lockedTarget) {
                throw new \RuntimeException('Target folio not found during transferCharge.');
            }

            $transaction->void();

            return $this->postDebit(
                $lockedTarget,
                $transaction->category,
                $transaction->description,
                $transaction->amount,
                $transaction->posted_by,
                null,
                $transaction->reference_type,
                $transaction->reference_id,
            );
        });
    }

    public function getBalance(Folio $folio): int
    {
        $folio->refresh();

        return $folio->outstanding_balance;
    }

    public function closeFolio(Folio $folio): void
    {
        DB::transaction(function () use ($folio) {
            $lockedFolio = Folio::where('id', $folio->id)->lockForUpdate()->first();

            if (! $lockedFolio) {
                throw new \RuntimeException('Folio not found during closeFolio.');
            }

            $balance = $this->getBalance($lockedFolio);

            if ($balance !== 0) {
                throw new \LogicException("Cannot close folio with outstanding balance of {$balance} cents.");
            }

            $lockedFolio->update([
                'status' => 'closed',
                'is_settled' => true,
                'closed_at' => now(),
                'version' => $lockedFolio->version + 1,
            ]);

            event(new FolioClosed($lockedFolio));
        });
    }

    public function initiateDispute(Folio $folio, ?int $transactionId, string $reason, int $userId): FolioDispute
    {
        $amountDisputed = 0;
        if ($transactionId) {
            $transaction = Transaction::findOrFail($transactionId);
            $amountDisputed = $transaction->amount;
        }

        $dispute = FolioDispute::create([
            'folio_id' => $folio->id,
            'currency_code' => $folio->currency_code,
            'transaction_id' => $transactionId,
            'disputed_by' => $userId,
            'status' => 'open',
            'reason' => $reason,
            'amount_disputed' => $amountDisputed,
        ]);

        event(new DisputeInitiated($dispute));

        return $dispute;
    }

    public function resolveDispute(FolioDispute $dispute, int $resolverId, string $notes): void
    {
        $dispute->resolve($resolverId, $notes);
        $fresh = $dispute->fresh();
        if ($fresh) {
            event(new DisputeResolved($fresh));
        }
    }

    public function rejectDispute(FolioDispute $dispute, int $resolverId, string $notes): void
    {
        $dispute->reject($resolverId, $notes);
        $fresh = $dispute->fresh();
        if ($fresh) {
            event(new DisputeResolved($fresh));
        }
    }

    public function postPosCharge(PosCharge $posCharge, int $userId): Transaction
    {
        $branch = $posCharge->branch;
        $taxRateBps = (int) ($branch->tax_rate * 100);

        return $this->postDebit(
            $posCharge->folio,
            $posCharge->outlet,
            $this->buildPosDescription($posCharge),
            $posCharge->total,
            $userId,
            $taxRateBps,
            PosCharge::class,
            $posCharge->id,
        );
    }

    /**
     * @return array{folio: Folio, branch: Branch, reservation: Reservation|null, guest_name: string, transactions: Collection<int, Transaction>, debits_total: int, tax_total: int, credits_total: int, balance: int, generated_at: string, rate_breakdown: array<string, mixed>|null}
     */
    public function generateBillData(Folio $folio): array
    {
        $folio->load(['reservation.room', 'reservation.roomType', 'reservation.branch', 'transactions.poster', 'branch']);

        $transactions = $folio->transactions()
            ->where('is_voided', false)
            ->orderBy('created_at')
            ->get();

        $debits = $transactions->where('type', 'debit');
        $credits = $transactions->where('type', 'credit');

        return [
            'folio' => $folio,
            'branch' => $folio->branch,
            'reservation' => $folio->reservation,
            'guest_name' => $folio->guest_name ?? $folio->reservation->guest_name ?? 'N/A',
            'transactions' => $transactions,
            'debits_total' => $debits->reduce(fn (int $carry, Transaction $t) => $carry + $t->amount, 0),
            'tax_total' => $debits->reduce(fn (int $carry, Transaction $t) => $carry + $t->tax_amount, 0),
            'credits_total' => $credits->reduce(fn (int $carry, Transaction $t) => $carry + $t->amount, 0),
            'balance' => $folio->outstanding_balance,
            'generated_at' => now()->toDateTimeString(),
            'rate_breakdown' => $folio->reservation?->rate_snapshot,
        ];
    }

    /**
     * Mirror a folio posting into the append-only journal inside the same
     * database transaction. The journal key derives from the transaction
     * id, so retried postings journal exactly once.
     */
    private function journalize(Folio $folio, Transaction $transaction, string $event, ?int $postedBy): void
    {
        // Zero-amount folio lines (adjustments, comps) are legal on the
        // folio but carry no ledger movement, so they skip the journal.
        if ($transaction->amount <= 0) {
            return;
        }

        if ($event === 'charge.posted' && $transaction->category === 'room_rate') {
            $event = 'room_charge.posted';
        }

        $branch = $folio->branch;

        (new PostingService(new JournalService))->post(
            branch: $branch,
            businessDate: (new BusinessDateService)->current($branch)->business_date->toDateString(),
            event: $event,
            amountMinor: $transaction->amount,
            source: $transaction,
            idempotency: ['scope' => 'folio.post', 'key' => "transactions:{$transaction->id}"],
            createdBy: $postedBy !== null ? User::find($postedBy) : null,
        );
    }

    /**
     * Compute tax through a profile and return the frozen snapshot payload.
     *
     * @return array{total_minor: int, snapshot: array<string, mixed>}
     */
    private function taxForProfile(Folio $folio, int $taxProfileId, string $category, int $amount): array
    {
        $profile = TaxProfile::where('id', $taxProfileId)
            ->where('branch_id', $folio->branch_id)
            ->active()
            ->firstOrFail();

        $taxService = new TaxService;
        $exempt = $taxService->exemptionsFor($folio->branch_id, $folio->reservation);
        $computed = $taxService->compute($amount, $profile, $category, $exempt);

        return ['total_minor' => $computed['total_minor'], 'snapshot' => $computed['snapshot']];
    }

    /**
     * Get or create a payer window on the folio.
     */
    public function createWindow(Folio $folio, string $code, string $payerType = 'guest', ?int $cityLedgerAccountId = null): FolioWindow
    {
        return FolioWindow::firstOrCreate(
            ['folio_id' => $folio->id, 'code' => $code],
            ['payer_type' => $payerType, 'city_ledger_account_id' => $cityLedgerAccountId],
        );
    }

    /**
     * Resolve where a charge lands: explicit window > active routing rule
     * for the category > the default room window (created on demand).
     */
    public function resolveWindow(Folio $folio, string $category, ?string $windowCode = null): FolioWindow
    {
        if ($windowCode !== null) {
            return $this->createWindow($folio, $windowCode);
        }

        $rule = FolioRoutingRule::where('folio_id', $folio->id)
            ->where('charge_category', $category)
            ->where('active', true)
            ->orderBy('priority')
            ->first();

        if ($rule) {
            $target = FolioWindow::find($rule->target_window_id);

            if ($target) {
                return $target;
            }
        }

        return $this->createWindow($folio, FolioWindow::CODE_ROOM);
    }

    /**
     * Split a transaction across windows by percent (bps) or exact minor
     * amounts. Legs must sum to the parent amount; the remainder goes to
     * the first leg. Presentational only: the parent posting already
     * journaled, so splits never touch the ledger.
     *
     * @param  list<array{window_code?: string, window_id?: int, percent_bps?: int, amount_minor?: int}>  $legs
     * @return list<TransactionSplit>
     */
    public function splitTransaction(Transaction $transaction, array $legs): array
    {
        if ($transaction->is_voided) {
            throw new \InvalidArgumentException('Cannot split a voided transaction.');
        }

        if (count($legs) < 2) {
            throw new \InvalidArgumentException('A split needs at least two legs.');
        }

        return DB::transaction(function () use ($transaction, $legs) {
            $folio = Folio::where('id', $transaction->folio_id)->lockForUpdate()->firstOrFail();

            if (TransactionSplit::where('transaction_id', $transaction->id)->exists()) {
                throw new \LogicException('Transaction is already split. Void the split legs first.');
            }

            $resolved = [];
            $percentTotal = 0;
            $amountTotal = 0;
            $hasPercent = false;
            $hasAmount = false;

            foreach ($legs as $leg) {
                $window = $this->legWindow($folio, $leg);

                if (isset($leg['percent_bps'])) {
                    $hasPercent = true;
                    $percentTotal += $leg['percent_bps'];
                    $resolved[] = ['window' => $window, 'percent_bps' => $leg['percent_bps'], 'amount_minor' => null];
                } elseif (isset($leg['amount_minor'])) {
                    $hasAmount = true;
                    $amountTotal += $leg['amount_minor'];
                    $resolved[] = ['window' => $window, 'percent_bps' => null, 'amount_minor' => $leg['amount_minor']];
                } else {
                    throw new \InvalidArgumentException('Each split leg needs percent_bps or amount_minor.');
                }
            }

            if ($hasPercent && $hasAmount) {
                throw new \InvalidArgumentException('Mixing percent and amount legs in one split is not allowed.');
            }

            if ($hasPercent) {
                if ($percentTotal !== 10000) {
                    throw new \InvalidArgumentException('Split percents must total 10000 bps.');
                }

                $assigned = 0;
                foreach ($resolved as $i => $leg) {
                    $amount = (int) floor($transaction->amount * $leg['percent_bps'] / 10000);
                    $resolved[$i]['amount_minor'] = $amount;
                    $assigned += $amount;
                }
                $resolved[0]['amount_minor'] += $transaction->amount - $assigned;
            } elseif ($amountTotal !== $transaction->amount) {
                throw new \InvalidArgumentException('Split amounts must sum to the transaction amount.');
            }

            $splits = [];
            foreach ($resolved as $leg) {
                $splits[] = TransactionSplit::create([
                    'transaction_id' => $transaction->id,
                    'target_window_id' => $leg['window']->id,
                    'amount_minor' => $leg['amount_minor'],
                    'percent_bps' => $leg['percent_bps'],
                ]);
            }

            return $splits;
        });
    }

    /**
     * Transfer a charge to the group master folio: void the original
     * (with a reversal journal), re-post on the master linked back to the
     * source. Idempotent per source transaction.
     */
    public function transferToMaster(Transaction $transaction, Folio $masterFolio, ?int $postedBy = null): Transaction
    {
        return DB::transaction(function () use ($transaction, $masterFolio, $postedBy) {
            $existing = Transaction::where('transfer_of_transaction_id', $transaction->id)->first();

            if ($existing) {
                return $existing;
            }

            if ($transaction->is_voided) {
                throw new \InvalidArgumentException('Cannot transfer a voided transaction.');
            }

            $lockedMaster = Folio::where('id', $masterFolio->id)->lockForUpdate()->first();

            if (! $lockedMaster) {
                throw new \RuntimeException('Master folio not found during transfer.');
            }

            $transaction->void();

            $this->reverseJournal($transaction, $postedBy);

            $moved = $this->postDebit(
                $lockedMaster,
                $transaction->category,
                $transaction->description.' (transferred)',
                $transaction->amount,
                $postedBy ?? $transaction->posted_by,
                null,
                $transaction->reference_type,
                $transaction->reference_id,
            );

            $moved->update([
                'group_master_folio_id' => $lockedMaster->id,
                'transfer_of_transaction_id' => $transaction->id,
            ]);

            event(new FolioTransferred($transaction, $moved, $lockedMaster));

            return $moved->fresh() ?? $moved;
        });
    }

    /**
     * @param  array{window_code?: string, window_id?: int, percent_bps?: int, amount_minor?: int}  $leg
     */
    private function legWindow(Folio $folio, array $leg): FolioWindow
    {
        if (isset($leg['window_id'])) {
            $window = FolioWindow::where('id', $leg['window_id'])->where('folio_id', $folio->id)->first();

            if (! $window) {
                throw new \InvalidArgumentException('Split window does not belong to the folio.');
            }

            return $window;
        }

        if (isset($leg['window_code'])) {
            return $this->createWindow($folio, $leg['window_code']);
        }

        throw new \InvalidArgumentException('Each split leg needs window_code or window_id.');
    }

    private function reverseJournal(Transaction $transaction, ?int $postedBy): void
    {
        $folio = Folio::find($transaction->folio_id);
        $branch = $folio?->branch;

        if ($branch === null || $transaction->amount <= 0) {
            return;
        }

        $businessDate = $transaction->business_date?->toDateString()
            ?? (new BusinessDateService)->current($branch)->business_date->toDateString();

        (new PostingService(new JournalService))->post(
            branch: $branch,
            businessDate: $businessDate,
            event: 'void.reversal',
            amountMinor: $transaction->amount,
            source: $transaction,
            idempotency: ['scope' => 'folio.void', 'key' => "transactions:{$transaction->id}"],
            createdBy: $postedBy !== null ? User::find($postedBy) : null,
        );
    }

    /**
     * Open drawer shift of the posting user on this branch, if any.
     * Postings without a user (system, portal) never enter a drawer.
     */
    private function openShiftId(?int $postedBy, int $branchId): ?int
    {
        if ($postedBy === null) {
            return null;
        }

        $shift = CashierShift::where('user_id', $postedBy)
            ->where('branch_id', $branchId)
            ->open()
            ->first();

        return $shift?->id;
    }

    private function buildPosDescription(PosCharge $posCharge): string
    {
        $itemCount = count($posCharge->items);
        $firstItem = $posCharge->items[0]['name'] ?? 'POS Charge';

        if ($itemCount === 1) {
            return "{$posCharge->outlet}: {$firstItem}";
        }

        return "{$posCharge->outlet}: {$firstItem} + ".($itemCount - 1).' more item'.($itemCount > 2 ? 's' : '');
    }
}
