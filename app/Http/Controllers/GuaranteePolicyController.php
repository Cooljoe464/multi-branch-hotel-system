<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Branch;
use App\Models\GuaranteePolicy;
use App\Models\RatePlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GuaranteePolicyController extends Controller
{
    use EnsuresBranchAccess;

    public function index(Branch $branch): Response
    {
        $this->ensureBranchAccess($branch);

        return Inertia::render('rates/Guarantees', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'policies' => GuaranteePolicy::forBranch($branch->id)->with('ratePlan')->orderByDesc('id')->get(),
            'plans' => RatePlan::forBranch($branch->id)->active()->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request, Branch $branch): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $request->validate([
            'kind' => 'required|string|in:deposit_schedule,card_guarantee,company_guarantee',
            'rate_plan_id' => 'nullable|exists:rate_plans,id',
            'deposit_bps' => 'nullable|integer|min:0|max:10000',
            'due_hours_before_arrival' => 'nullable|integer|min:0|max:720',
            'hold_hours' => 'nullable|integer|min:1|max:720',
            'cancel_free_until_hours' => 'nullable|integer|min:0|max:720',
            'no_show_fee' => 'nullable|string|in:first_night,percent',
            'no_show_fee_bps' => 'nullable|integer|min:0|max:10000',
        ]);

        if ($request->filled('rate_plan_id')) {
            $plan = RatePlan::find($request->integer('rate_plan_id'));
            abort_unless($plan && $plan->branch_id === $branch->id, 422, 'The rate plan must belong to this property.');
        }

        GuaranteePolicy::create([
            'branch_id' => $branch->id,
            'rate_plan_id' => $request->input('rate_plan_id'),
            'kind' => $request->string('kind')->value(),
            'rules' => [
                'deposit_bps' => $request->integer('deposit_bps', 0),
                'due_hours_before_arrival' => $request->integer('due_hours_before_arrival', 48),
                'hold_hours' => $request->integer('hold_hours', 24),
                'cancel_free_until_hours' => $request->integer('cancel_free_until_hours', 24),
                'no_show_fee' => $request->string('no_show_fee', 'first_night')->value(),
                'no_show_fee_bps' => $request->integer('no_show_fee_bps', 0),
            ],
            'is_active' => true,
        ]);

        return $this->flashSuccess('Guarantee policy created.');
    }

    public function destroy(Branch $branch, GuaranteePolicy $policy): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($policy->branch_id === $branch->id, 404);

        $policy->delete();

        return $this->flashSuccess('Guarantee policy deleted.');
    }
}
