<?php

namespace App\Http\Controllers;

use App\Models\RoomType;
use App\Models\YieldRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class YieldRuleController extends Controller
{
    use Concerns\EnsuresBranchAccess;

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $branchId = (int) $user->branch_id;

        $rules = YieldRule::forBranch($branchId)
            ->with('roomType')
            ->orderBy('priority', 'desc')
            ->paginate(25)
            ->withQueryString();

        $roomTypes = RoomType::forBranch($branchId)->active()->get();

        return Inertia::render('settings/YieldRules', [
            'rules' => $rules,
            'roomTypes' => $roomTypes,
            'filters' => $request->only(['page']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'room_type_id' => 'nullable|exists:room_types,id',
            'min_occupancy_pct' => 'required|integer|min:0|max:100',
            'max_occupancy_pct' => 'required|integer|min:0|max:100|gte:min_occupancy_pct',
            'rate_multiplier' => 'required|numeric|min:0.5|max:3.0',
            'mlos_override' => 'nullable|integer|min:1|max:30',
            'cta_override' => 'nullable|boolean',
            'priority' => 'integer|min:0',
            'notes' => 'nullable|string|max:255',
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        YieldRule::create([
            'branch_id' => $user->branch_id,
            'room_type_id' => $request->filled('room_type_id') ? $request->integer('room_type_id') : null,
            'min_occupancy_pct' => $request->integer('min_occupancy_pct'),
            'max_occupancy_pct' => $request->integer('max_occupancy_pct'),
            'rate_multiplier' => $request->float('rate_multiplier'),
            'mlos_override' => $request->filled('mlos_override') ? $request->integer('mlos_override') : null,
            'cta_override' => $request->has('cta_override') ? $request->boolean('cta_override') : null,
            'priority' => $request->integer('priority'),
            'notes' => $request->string('notes')->value() ?: null,
            'is_active' => true,
        ]);

        return $this->flashSuccess('Yield rule created.');
    }

    public function update(Request $request, YieldRule $yieldRule): RedirectResponse
    {
        $this->ensureSameBranchAccess($yieldRule->branch_id, (int) $request->user()->branch_id);

        $request->validate([
            'room_type_id' => 'nullable|exists:room_types,id',
            'min_occupancy_pct' => 'sometimes|integer|min:0|max:100',
            'max_occupancy_pct' => 'sometimes|integer|min:0|max:100',
            'rate_multiplier' => 'sometimes|numeric|min:0.5|max:3.0',
            'mlos_override' => 'nullable|integer|min:1|max:30',
            'cta_override' => 'nullable|boolean',
            'is_active' => 'boolean',
            'priority' => 'integer|min:0',
        ]);

        $data = [];
        if ($request->has('room_type_id')) {
            $data['room_type_id'] = $request->integer('room_type_id') ?: null;
        }
        if ($request->has('min_occupancy_pct')) {
            $data['min_occupancy_pct'] = $request->integer('min_occupancy_pct');
        }
        if ($request->has('max_occupancy_pct')) {
            $data['max_occupancy_pct'] = $request->integer('max_occupancy_pct');
        }
        if ($request->has('rate_multiplier')) {
            $data['rate_multiplier'] = $request->float('rate_multiplier');
        }
        if ($request->has('mlos_override')) {
            $data['mlos_override'] = $request->integer('mlos_override') ?: null;
        }
        if ($request->has('cta_override')) {
            $data['cta_override'] = $request->boolean('cta_override');
        }
        if ($request->has('is_active')) {
            $data['is_active'] = $request->boolean('is_active');
        }
        if ($request->has('priority')) {
            $data['priority'] = $request->integer('priority');
        }

        $yieldRule->update($data);

        return $this->flashSuccess('Yield rule updated.');
    }

    public function destroy(YieldRule $yieldRule): RedirectResponse
    {
        $this->ensureSameBranchAccess($yieldRule->branch_id, (int) request()->user()->branch_id);

        $yieldRule->delete();

        return $this->flashSuccess('Yield rule deleted.');
    }
}
