<?php

namespace App\Http\Controllers;

use App\Models\RatePlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RatePlanController extends Controller
{
    use Concerns\EnsuresBranchAccess;

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $branchId = (int) $user->branch_id;

        $ratePlans = RatePlan::forBranch($branchId)
            ->with(['roomType'])
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')->value()))
            ->orderBy('type')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('settings/RatePlans', [
            'ratePlans' => $ratePlans,
            'filters' => $request->only(['type']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50',
            'type' => 'required|string|in:bar,corporate,package,promotional',
            'room_type_id' => 'nullable|exists:room_types,id',
            'rate_multiplier' => 'required|numeric|min:0.01|max:10',
            'is_negotiable' => 'boolean',
            'min_rate' => 'nullable|integer|min:0',
            'max_rate' => 'nullable|integer|min:0',
            'valid_from' => 'required|date',
            'valid_to' => 'nullable|date|after_or_equal:valid_from',
            'base_plan_id' => 'nullable|exists:rate_plans,id',
            'derivation_bps' => 'nullable|integer|min:-10000|max:100000',
            'derivation_fixed_minor' => 'nullable|integer|min:-100000000|max:100000000',
            'package_components' => 'nullable|array|max:10',
            'package_components.*.code' => 'required_with:package_components|string|max:50',
            'package_components.*.label' => 'required_with:package_components|string|max:255',
            'package_components.*.amount_minor' => 'required_with:package_components|integer|min:0',
            'package_components.*.category' => 'required_with:package_components|string|max:50',
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $branch = $user->currentBranch;
        abort_unless($branch !== null, 422, 'No current branch selected.');

        if ($request->filled('base_plan_id')) {
            $base = RatePlan::find($request->integer('base_plan_id'));
            abort_unless($base && $base->branch_id === (int) $user->branch_id, 422, 'The base plan must belong to this property.');
        }

        RatePlan::create([
            'branch_id' => $user->branch_id,
            'currency_code' => $branch->currency_code,
            'name' => $request->string('name')->value(),
            'code' => $request->string('code')->value(),
            'type' => $request->string('type')->value(),
            'room_type_id' => $request->input('room_type_id'),
            'rate_multiplier' => $request->float('rate_multiplier'),
            'is_negotiable' => $request->boolean('is_negotiable'),
            'min_rate' => $request->input('min_rate'),
            'max_rate' => $request->input('max_rate'),
            'valid_from' => $request->string('valid_from')->value(),
            'valid_to' => $request->string('valid_to')->value() ?: null,
            'is_active' => true,
            'base_plan_id' => $request->input('base_plan_id'),
            'derivation_bps' => $request->input('derivation_bps'),
            'derivation_fixed_minor' => $request->input('derivation_fixed_minor'),
            'package_components' => $request->input('package_components'),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Rate plan created.']);

        return redirect()->route('rate-plans.index');
    }

    public function update(Request $request, RatePlan $ratePlan): RedirectResponse
    {
        $this->ensureBranchAccess($ratePlan->branch);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'rate_multiplier' => 'sometimes|numeric|min:0.01|max:10',
            'is_active' => 'boolean',
            'min_rate' => 'nullable|integer|min:0',
            'max_rate' => 'nullable|integer|min:0',
            'valid_to' => 'nullable|date',
            'base_plan_id' => 'nullable|exists:rate_plans,id',
            'derivation_bps' => 'nullable|integer|min:-10000|max:100000',
            'derivation_fixed_minor' => 'nullable|integer|min:-100000000|max:100000000',
            'package_components' => 'nullable|array|max:10',
            'package_components.*.code' => 'required_with:package_components|string|max:50',
            'package_components.*.label' => 'required_with:package_components|string|max:255',
            'package_components.*.amount_minor' => 'required_with:package_components|integer|min:0',
            'package_components.*.category' => 'required_with:package_components|string|max:50',
        ]);

        if ($request->has('base_plan_id') && $request->filled('base_plan_id')) {
            abort_unless($request->integer('base_plan_id') !== $ratePlan->id, 422, 'A rate plan cannot derive from itself.');

            $base = RatePlan::find($request->integer('base_plan_id'));
            abort_unless($base && $base->branch_id === $ratePlan->branch_id, 422, 'The base plan must belong to this property.');
        }

        $ratePlan->update([
            'name' => $request->input('name'),
            'rate_multiplier' => $request->input('rate_multiplier'),
            'is_active' => $request->boolean('is_active'),
            'min_rate' => $request->input('min_rate'),
            'max_rate' => $request->input('max_rate'),
            'valid_to' => $request->input('valid_to'),
            'base_plan_id' => $request->has('base_plan_id') ? $request->input('base_plan_id') : $ratePlan->base_plan_id,
            'derivation_bps' => $request->has('derivation_bps') ? $request->input('derivation_bps') : $ratePlan->derivation_bps,
            'derivation_fixed_minor' => $request->has('derivation_fixed_minor') ? $request->input('derivation_fixed_minor') : $ratePlan->derivation_fixed_minor,
            'package_components' => $request->has('package_components') ? $request->input('package_components') : $ratePlan->package_components,
        ]);

        return $this->flashSuccess('Rate plan updated.');
    }

    public function destroy(RatePlan $ratePlan): RedirectResponse
    {
        $this->ensureBranchAccess($ratePlan->branch);

        $ratePlan->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Rate plan deleted.']);

        return redirect()->route('rate-plans.index');
    }
}
