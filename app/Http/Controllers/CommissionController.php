<?php

namespace App\Http\Controllers;

use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Models\CommissionAccrual;
use App\Models\CommissionPayout;
use App\Models\CommissionRule;
use App\Services\CommissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CommissionController extends Controller
{
    use Concerns\EnsuresBranchAccess;

    public function index(Request $request, Branch $branch): Response
    {
        $this->ensureBranchAccess($branch);

        $request->validate([
            'source' => 'nullable|string|max:64',
            'status' => 'nullable|string|in:accrued,invoiced,paid,disputed',
        ]);

        $accruals = CommissionAccrual::forBranch($branch->id)
            ->with(['reservation', 'payouts'])
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->string('source')->value()))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->value()))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $payouts = CommissionPayout::forBranch($branch->id)
            ->with('accruals')
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        $rules = CommissionRule::forBranch($branch->id)->orderBy('source')->get();

        return Inertia::render('finance/Commissions', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'accruals' => $accruals,
            'payouts' => $payouts,
            'rules' => $rules,
            'filters' => $request->only(['source', 'status']),
        ]);
    }

    public function storeRule(Request $request, Branch $branch): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $request->validate([
            'source' => 'required|string|max:64',
            'rate_bps' => 'required|integer|min:0|max:10000',
            'base' => 'required|string|in:net_room,gross',
        ]);

        CommissionRule::updateOrCreate(
            ['branch_id' => $branch->id, 'source' => $request->string('source')->value()],
            [
                'rate_bps' => $request->integer('rate_bps'),
                'base' => $request->string('base')->value(),
                'active' => true,
            ],
        );

        return $this->flashSuccess('Commission rule saved.');
    }

    public function storePayout(Request $request, Branch $branch): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $request->validate([
            'source' => 'required|string|max:64',
            'accrual_ids' => 'required|array|min:1',
            'accrual_ids.*' => 'integer|min:1',
            'amount_minor' => 'nullable|integer|min:0',
            'reference' => 'nullable|string|max:128',
        ]);

        $rawIds = $request->input('accrual_ids');
        $accrualIds = [];
        if (is_array($rawIds)) {
            foreach ($rawIds as $id) {
                if (is_int($id)) {
                    $accrualIds[] = $id;
                }
            }
        }

        $rawAmount = $request->input('amount_minor');
        $amountMinor = is_int($rawAmount) ? $rawAmount : null;

        try {
            $payout = (new CommissionService)->createPayout(
                $branch,
                $request->string('source')->value(),
                $accrualIds,
                $amountMinor,
                $request->string('reference')->value() ?: null,
                $request->user(),
            );
        } catch (AvailabilityException $e) {
            return back()->withErrors(['accrual_ids' => $e->getMessage()]);
        }

        return $this->flashSuccess("Payout #{$payout->id} built.");
    }

    public function pay(Request $request, Branch $branch, CommissionPayout $payout): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($payout->branch_id === $branch->id, 404);

        try {
            (new CommissionService)->payPayout($payout, $request->user());
        } catch (AvailabilityException $e) {
            return back()->withErrors(['payout' => $e->getMessage()]);
        }

        return $this->flashSuccess('Payout paid and payable cleared.');
    }

    public function dispute(Branch $branch, CommissionAccrual $accrual): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($accrual->branch_id === $branch->id, 404);

        try {
            (new CommissionService)->dispute($accrual);
        } catch (AvailabilityException $e) {
            return back()->withErrors(['accrual' => $e->getMessage()]);
        }

        return $this->flashSuccess('Accrual disputed; excluded from payouts.');
    }

    public function resolveDispute(Branch $branch, CommissionAccrual $accrual): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($accrual->branch_id === $branch->id, 404);

        try {
            (new CommissionService)->resolveDispute($accrual);
        } catch (AvailabilityException $e) {
            return back()->withErrors(['accrual' => $e->getMessage()]);
        }

        return $this->flashSuccess('Dispute resolved; accrual payable again.');
    }
}
