<?php

namespace App\Services;

use App\Events\DisputeInitiated;
use App\Events\DisputeResolved;
use App\Events\FolioClosed;
use App\Events\PaymentReceived;
use App\Events\TransactionPosted;
use App\Models\Branch;
use App\Models\Folio;
use App\Models\FolioDispute;
use App\Models\PosCharge;
use App\Models\Reservation;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class FolioService
{
    private function resolveCurrencyCode(int $branchId): string
    {
        return Branch::find($branchId)?->currency_code ?? 'NGN';
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

    public function postDebit(Folio $folio, string $category, string $description, int $amount, ?int $postedBy = null, ?int $taxRateBps = null, ?string $referenceType = null, ?int $referenceId = null): Transaction
    {
        return DB::transaction(function () use ($folio, $category, $description, $amount, $postedBy, $taxRateBps, $referenceType, $referenceId) {
            $lockedFolio = Folio::where('id', $folio->id)->lockForUpdate()->first();

            if (! $lockedFolio) {
                throw new \RuntimeException('Folio not found during postDebit.');
            }

            $taxAmount = 0;
            if ($taxRateBps !== null && $taxRateBps > 0) {
                $taxAmount = (int) round($amount * $taxRateBps / 10000);
            }

            $transaction = Transaction::create([
                'folio_id' => $folio->id,
                'currency_code' => $folio->currency_code,
                'type' => 'debit',
                'category' => $category,
                'description' => $description,
                'amount' => $amount,
                'posted_by' => $postedBy,
                'is_taxable' => $taxRateBps !== null,
                'tax_amount' => $taxAmount,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);

            $lockedFolio->update([
                'balance' => $lockedFolio->outstanding_balance,
            ]);

            event(new TransactionPosted($lockedFolio, $transaction));

            return $transaction;
        });
    }

    public function postCredit(Folio $folio, string $category, string $description, int $amount, ?int $postedBy = null, ?string $referenceType = null, ?int $referenceId = null): Transaction
    {
        return DB::transaction(function () use ($folio, $category, $description, $amount, $postedBy, $referenceType, $referenceId) {
            $lockedFolio = Folio::where('id', $folio->id)->lockForUpdate()->first();

            if (! $lockedFolio) {
                throw new \RuntimeException('Folio not found during postCredit.');
            }

            $transaction = Transaction::create([
                'folio_id' => $folio->id,
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
            ]);

            event(new TransactionPosted($lockedFolio, $transaction));

            return $transaction;
        });
    }

    public function postManualCharge(Folio $folio, string $category, string $description, int $amount, ?int $taxRateBps = null, ?int $postedBy = null): Transaction
    {
        return $this->postDebit(
            $folio,
            $category,
            $description,
            $amount,
            $postedBy,
            $taxRateBps,
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
     * @return array{folio: Folio, branch: Branch, reservation: Reservation|null, guest_name: string, transactions: Collection<int, Transaction>, debits_total: int, tax_total: int, credits_total: int, balance: int, generated_at: string}
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
        ];
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
