<?php

namespace App\Http\Controllers;

use App\Models\RateOverride;
use App\Models\RoomType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RateOverrideController extends Controller
{
    use Concerns\EnsuresBranchAccess;

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $branchId = (int) $user->branch_id;

        $overrides = RateOverride::forBranch($branchId)
            ->with('roomType')
            ->orderBy('start_date', 'desc')
            ->paginate(25)
            ->withQueryString();

        $roomTypes = RoomType::forBranch($branchId)->active()->get();

        return Inertia::render('settings/RateOverrides', [
            'overrides' => $overrides,
            'roomTypes' => $roomTypes,
            'filters' => $request->only(['page']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $roomTypeIdRaw = $request->string('room_type_id')->value();
        $request->merge(['room_type_id' => ($roomTypeIdRaw === '' || $roomTypeIdRaw === 'none') ? null : $roomTypeIdRaw]);

        $request->validate([
            'room_type_id' => 'nullable|exists:room_types,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'rate_override' => 'nullable|integer|min:0',
            'mlos' => 'nullable|integer|min:1|max:30',
            'cta' => 'boolean',
            'ctd' => 'boolean',
            'notes' => 'nullable|string|max:255',
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $branchId = (int) $user->branch_id;
        $currentBranch = $user->currentBranch;
        abort_unless($currentBranch !== null, 422, 'No active property.');
        $startDate = $request->string('start_date')->value();
        $endDate = $request->string('end_date')->value();
        $roomTypeId = $request->filled('room_type_id') ? $request->integer('room_type_id') : null;

        $overlap = RateOverride::forBranch($branchId)
            ->active()
            ->forRoomType($roomTypeId)
            ->overlapping($startDate, $endDate)
            ->exists();

        if ($overlap) {
            return back()->withErrors(['start_date' => 'An override already exists for this room type and date range.']);
        }

        RateOverride::create([
            'branch_id' => $branchId,
            'currency_code' => $currentBranch->currency_code,
            'room_type_id' => $roomTypeId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'rate_override' => $request->filled('rate_override') ? $request->integer('rate_override') : null,
            'mlos' => $request->filled('mlos') ? $request->integer('mlos') : null,
            'cta' => $request->boolean('cta'),
            'ctd' => $request->boolean('ctd'),
            'notes' => $request->string('notes')->value() ?: null,
            'is_active' => true,
        ]);

        return $this->flashSuccess('Rate override created.');
    }

    public function update(Request $request, RateOverride $rateOverride): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor !== null, 401);
        $this->ensureSameBranchAccess($rateOverride->branch_id, (int) $actor->branch_id);

        $request->validate([
            'room_type_id' => 'nullable|exists:room_types,id',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
            'rate_override' => 'nullable|integer|min:0',
            'mlos' => 'nullable|integer|min:1|max:30',
            'cta' => 'boolean',
            'ctd' => 'boolean',
            'is_active' => 'boolean',
            'notes' => 'nullable|string|max:255',
        ]);

        $data = [];
        if ($request->has('room_type_id')) {
            $data['room_type_id'] = $request->integer('room_type_id') ?: null;
        }
        if ($request->has('start_date')) {
            $data['start_date'] = $request->string('start_date')->value();
        }
        if ($request->has('end_date')) {
            $data['end_date'] = $request->string('end_date')->value();
        }
        if ($request->has('rate_override')) {
            $data['rate_override'] = $request->integer('rate_override') ?: null;
        }
        if ($request->has('mlos')) {
            $data['mlos'] = $request->integer('mlos') ?: null;
        }
        if ($request->has('cta')) {
            $data['cta'] = $request->boolean('cta');
        }
        if ($request->has('ctd')) {
            $data['ctd'] = $request->boolean('ctd');
        }
        if ($request->has('is_active')) {
            $data['is_active'] = $request->boolean('is_active');
        }
        if ($request->has('notes')) {
            $data['notes'] = $request->string('notes')->value() ?: null;
        }

        $rateOverride->update($data);

        return $this->flashSuccess('Rate override updated.');
    }

    public function destroy(RateOverride $rateOverride): RedirectResponse
    {
        $actor = request()->user();
        abort_unless($actor !== null, 401);
        $this->ensureSameBranchAccess($rateOverride->branch_id, (int) $actor->branch_id);

        $rateOverride->delete();

        return $this->flashSuccess('Rate override deleted.');
    }
}
