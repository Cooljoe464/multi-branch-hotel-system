<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Branch;
use App\Models\PaymentTransaction;
use App\Models\Transaction;
use App\Models\VoidRefundApproval;
use App\Models\VoidRefundCode;
use App\Services\VoidRefundService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VoidRefundController extends Controller
{
    use EnsuresBranchAccess;

    public function __construct(private VoidRefundService $voidRefunds) {}

    public function index(Branch $branch): Response
    {
        $this->ensureBranchAccess($branch);

        $approvals = VoidRefundApproval::with(['reasonCode'])
            ->whereHas('reasonCode', fn ($q) => $q->where('branch_id', $branch->id))
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $codes = VoidRefundCode::where('branch_id', $branch->id)->active()->get();

        return Inertia::render('finance/Voids', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'approvals' => $approvals,
            'codes' => $codes,
        ]);
    }

    public function requestVoid(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->ensureBranchAccess($transaction->folio->branch);

        $request->validate([
            'reason_code_id' => 'required|exists:void_refund_codes,id',
            'note' => 'nullable|string|max:500',
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $code = VoidRefundCode::findOrFail($request->integer('reason_code_id'));

        $this->voidRefunds->requestVoid(
            $transaction,
            $code,
            $user,
            $request->string('note')->value() !== '' ? $request->string('note')->value() : null,
        );

        return $this->flashSuccess('Void request recorded.');
    }

    public function approve(Request $request, VoidRefundApproval $approval): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $this->voidRefunds->approve($approval, $user);

        return $this->flashSuccess('Approval granted and executed.');
    }

    public function reject(Request $request, VoidRefundApproval $approval): RedirectResponse
    {
        $request->validate(['note' => 'nullable|string|max:500']);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $this->voidRefunds->reject(
            $approval,
            $user,
            $request->string('note')->value() !== '' ? $request->string('note')->value() : null,
        );

        return $this->flashSuccess('Approval rejected.');
    }

    public function refund(Request $request, PaymentTransaction $paymentTransaction): RedirectResponse
    {
        $this->ensureBranchAccess($paymentTransaction->branch);

        $request->validate([
            'reason_code_id' => 'required|exists:void_refund_codes,id',
            'amount_minor' => 'required|integer|min:1',
        ]);

        $code = VoidRefundCode::findOrFail($request->integer('reason_code_id'));

        $user = $request->user();
        abort_unless($user !== null, 401);

        $approval = VoidRefundApproval::create([
            'subject_type' => $paymentTransaction->getMorphClass(),
            'subject_id' => $paymentTransaction->id,
            'reason_code_id' => $code->id,
            'requested_by' => $user->id,
            'status' => VoidRefundApproval::STATUS_PENDING,
        ]);

        $this->voidRefunds->executeRefund($approval, $user, $request->integer('amount_minor'));

        return $this->flashSuccess('Refund executed.');
    }
}
