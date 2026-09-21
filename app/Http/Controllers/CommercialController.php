<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Branch;
use App\Models\CorporateAccount;
use App\Models\PromoCode;
use App\Models\RatePlan;
use App\Models\RateSeason;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Branch commercial setup: seasons, promo codes and corporate accounts.
 * One controller (one page) because the three resources are tiny,
 * branch-scoped siblings managed on the same screen.
 */
class CommercialController extends Controller
{
    use EnsuresBranchAccess;

    public function index(Branch $branch): Response
    {
        $this->ensureBranchAccess($branch);

        return Inertia::render('rates/Commercial', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'seasons' => RateSeason::forBranch($branch->id)->orderByDesc('priority')->get(),
            'promos' => PromoCode::forBranch($branch->id)->orderBy('code')->get(),
            'corporates' => CorporateAccount::forBranch($branch->id)->with('negotiatedPlan')->orderBy('code')->get(),
            'plans' => RatePlan::forBranch($branch->id)->active()->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function storeSeason(Request $request, Branch $branch): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50',
            'start_month' => 'required|integer|min:1|max:12',
            'start_day' => 'required|integer|min:1|max:31',
            'end_month' => 'required|integer|min:1|max:12',
            'end_day' => 'required|integer|min:1|max:31',
            'multiplier_bps' => 'required|integer|min:1000|max:50000',
            'priority' => 'nullable|integer|min:0|max:100',
        ]);

        RateSeason::create([
            'branch_id' => $branch->id,
            'name' => $request->string('name')->value(),
            'code' => strtoupper($request->string('code')->value()),
            'start_month' => $request->integer('start_month'),
            'start_day' => $request->integer('start_day'),
            'end_month' => $request->integer('end_month'),
            'end_day' => $request->integer('end_day'),
            'multiplier_bps' => $request->integer('multiplier_bps'),
            'priority' => $request->integer('priority', 0),
            'is_active' => true,
        ]);

        return $this->flashSuccess('Season created.');
    }

    public function destroySeason(Branch $branch, RateSeason $season): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($season->branch_id === $branch->id, 404);

        $season->delete();

        return $this->flashSuccess('Season deleted.');
    }

    public function storePromo(Request $request, Branch $branch): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $request->validate([
            'code' => 'required|string|max:50',
            'discount_bps' => 'nullable|integer|min:1|max:10000',
            'discount_fixed_minor' => 'nullable|integer|min:1',
            'max_uses' => 'nullable|integer|min:1',
            'min_nights' => 'nullable|integer|min:1|max:60',
            'valid_from' => 'required|date',
            'valid_to' => 'nullable|date|after_or_equal:valid_from',
        ]);

        if (! $request->filled('discount_bps') && ! $request->filled('discount_fixed_minor')) {
            return back()->withErrors(['discount_bps' => 'A percent or fixed discount is required.']);
        }

        PromoCode::create([
            'branch_id' => $branch->id,
            'code' => strtoupper($request->string('code')->value()),
            'discount_bps' => $request->input('discount_bps'),
            'discount_fixed_minor' => $request->input('discount_fixed_minor'),
            'max_uses' => $request->input('max_uses'),
            'min_nights' => $request->integer('min_nights', 1),
            'valid_from' => $request->string('valid_from')->value(),
            'valid_to' => $request->string('valid_to')->value() ?: null,
            'is_active' => true,
        ]);

        return $this->flashSuccess('Promo code created.');
    }

    public function destroyPromo(Branch $branch, PromoCode $promo): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($promo->branch_id === $branch->id, 404);

        $promo->delete();

        return $this->flashSuccess('Promo code deleted.');
    }

    public function storeCorporate(Request $request, Branch $branch): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50',
            'negotiated_plan_id' => 'nullable|exists:rate_plans,id',
            'discount_bps' => 'nullable|integer|min:0|max:10000',
            'ledger_account' => 'nullable|string|max:255',
        ]);

        if ($request->filled('negotiated_plan_id')) {
            $plan = RatePlan::find($request->integer('negotiated_plan_id'));
            abort_unless($plan && $plan->branch_id === $branch->id, 422, 'The negotiated plan must belong to this property.');
        }

        CorporateAccount::create([
            'branch_id' => $branch->id,
            'name' => $request->string('name')->value(),
            'code' => strtoupper($request->string('code')->value()),
            'negotiated_plan_id' => $request->input('negotiated_plan_id'),
            'discount_bps' => $request->integer('discount_bps', 0),
            'ledger_account' => $request->string('ledger_account')->value() ?: null,
            'is_active' => true,
        ]);

        return $this->flashSuccess('Corporate account created.');
    }

    public function destroyCorporate(Branch $branch, CorporateAccount $corporate): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($corporate->branch_id === $branch->id, 404);

        $corporate->delete();

        return $this->flashSuccess('Corporate account deleted.');
    }
}
