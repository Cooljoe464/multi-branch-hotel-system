<?php

namespace App\Services;

use App\Events\VoidApprovalDecided;
use App\Events\VoidApprovalRequested;
use App\Models\PaymentTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VoidRefundApproval;
use App\Models\VoidRefundCode;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Supervisor-gated voids and refunds. Protected reason codes (and
 * supervisor-only codes) create a pending approval that a DIFFERENT user
 * must decide; unprotected codes execute immediately. Approving twice is
 * a no-op; self-approval is rejected.
 */
class VoidRefundService
{
    public function requestVoid(Transaction $transaction, VoidRefundCode $code, User $requester, ?string $note = null): VoidRefundApproval
    {
        $this->guardCode($code, VoidRefundCode::KIND_VOID, $transaction->folio->branch_id);

        return DB::transaction(function () use ($transaction, $code, $requester, $note) {
            if ($transaction->is_voided) {
                throw new LogicException('Transaction is already voided.');
            }

            if (! $code->requires_supervisor) {
                $transaction->void($code->id);

                $approval = VoidRefundApproval::create([
                    'subject_type' => $transaction->getMorphClass(),
                    'subject_id' => $transaction->id,
                    'reason_code_id' => $code->id,
                    'requested_by' => $requester->id,
                    'approved_by' => $requester->id,
                    'status' => VoidRefundApproval::STATUS_APPROVED,
                    'note' => $note,
                ]);
                event(new VoidApprovalDecided($approval));

                return $approval->fresh() ?? $approval;
            }

            $approval = VoidRefundApproval::create([
                'subject_type' => $transaction->getMorphClass(),
                'subject_id' => $transaction->id,
                'reason_code_id' => $code->id,
                'requested_by' => $requester->id,
                'status' => VoidRefundApproval::STATUS_PENDING,
                'note' => $note,
            ]);
            event(new VoidApprovalRequested($approval));

            return $approval->fresh() ?? $approval;
        });
    }

    public function approve(VoidRefundApproval $approval, User $supervisor): VoidRefundApproval
    {
        return DB::transaction(function () use ($approval, $supervisor) {
            $locked = VoidRefundApproval::where('id', $approval->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                return $locked;
            }

            if ((int) $locked->requested_by === (int) $supervisor->id
                && (bool) $locked->reasonCode->requires_supervisor) {
                throw new LogicException('Supervisor approvals require a different user.');
            }

            if (! $supervisor->can('cashier.approve_void') && ! (bool) ($supervisor->is_global_admin ?? false)) {
                throw new LogicException('User cannot approve voids or refunds.');
            }

            $locked->update([
                'status' => VoidRefundApproval::STATUS_APPROVED,
                'approved_by' => $supervisor->id,
            ]);

            $this->execute($locked->fresh() ?? $locked, $supervisor);

            event(new VoidApprovalDecided($locked->fresh() ?? $locked));

            return $locked->fresh() ?? $locked;
        });
    }

    public function reject(VoidRefundApproval $approval, User $supervisor, ?string $note = null): VoidRefundApproval
    {
        $locked = VoidRefundApproval::where('id', $approval->id)->lockForUpdate()->firstOrFail();

        if (! $locked->isPending()) {
            return $locked;
        }

        $locked->update([
            'status' => VoidRefundApproval::STATUS_REJECTED,
            'approved_by' => $supervisor->id,
            'note' => $note ?? $locked->note,
        ]);

        event(new VoidApprovalDecided($locked->fresh() ?? $locked));

        return $locked->fresh() ?? $locked;
    }

    /**
     * Full refund through the gateway chain, gated by approval.
     */
    public function executeRefund(VoidRefundApproval $approval, User $supervisor, int $amountMinor): PaymentTransaction
    {
        $approved = $this->approve($approval, $supervisor);

        /** @var PaymentTransaction $subject */
        $subject = $approved->subject;

        $paymentService = new PaymentService;
        $refund = $paymentService->refundPayment($subject, $amountMinor, $supervisor->id);
        $refund->update(['refund_reason_code_id' => $approved->reason_code_id]);

        return $refund;
    }

    private function execute(VoidRefundApproval $approval, User $supervisor): void
    {
        $subject = $approval->subject;

        if ($subject instanceof Transaction) {
            $subject->void($approval->reason_code_id);

            return;
        }

        if ($subject instanceof PaymentTransaction) {
            $subject->update(['refund_reason_code_id' => $approval->reason_code_id]);
        }
    }

    private function guardCode(VoidRefundCode $code, string $kind, int $branchId): void
    {
        if (! $code->active || $code->kind !== $kind || (int) $code->branch_id !== $branchId) {
            throw new LogicException('Reason code is not valid for this operation.');
        }
    }

    public function seedCodes(int $branchId): void
    {
        $codes = [
            ['ERROR_CORRECTION', VoidRefundCode::KIND_VOID, false],
            ['DUPLICATE_CHARGE', VoidRefundCode::KIND_VOID, false],
            ['GUEST_COMPLAINT', VoidRefundCode::KIND_VOID, true],
            ['MANAGER_COMP', VoidRefundCode::KIND_REFUND, true],
            ['CHARGEBACK', VoidRefundCode::KIND_REFUND, true],
        ];

        foreach ($codes as [$code, $kind, $supervisor]) {
            VoidRefundCode::firstOrCreate(
                ['branch_id' => $branchId, 'code' => $code],
                ['kind' => $kind, 'requires_supervisor' => $supervisor, 'active' => true],
            );
        }
    }
}
