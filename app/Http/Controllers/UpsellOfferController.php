<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Branch;
use App\Models\RoomType;
use App\Models\UpsellOffer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UpsellOfferController extends Controller
{
    use EnsuresBranchAccess;

    public function index(Branch $branch): Response
    {
        $this->ensureBranchAccess($branch);

        return Inertia::render('upsells/Index', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'offers' => UpsellOffer::forBranch($branch->id)->orderBy('kind')->get(),
            'roomTypes' => RoomType::forBranch($branch->id)->active()->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function store(Request $request, Branch $branch): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $request->validate([
            'kind' => 'required|string|in:early_checkin,late_checkout,upgrade',
            'fee_minor' => 'required|integer|min:0',
            'cutoff_hour' => 'nullable|integer|min:0|max:23',
            'inventory_guard' => 'nullable|boolean',
            'target_room_type_id' => 'nullable|exists:room_types,id',
        ]);

        $targetId = $request->input('target_room_type_id');
        $target = null;

        if ($targetId !== null && is_numeric($targetId)) {
            $target = RoomType::findOrFail((int) $targetId);
            abort_unless($target->branch_id === $branch->id, 403);
        }

        UpsellOffer::create([
            'branch_id' => $branch->id,
            'kind' => $request->string('kind')->value(),
            'rules' => [
                'fee_minor' => $request->integer('fee_minor'),
                'cutoff_hour' => $request->integer('cutoff_hour', 12),
                'inventory_guard' => $request->boolean('inventory_guard', true),
                'target_room_type_id' => $target?->id,
            ],
            'active' => true,
        ]);

        return $this->flashSuccess('Upsell offer priced and activated.');
    }

    public function update(Request $request, Branch $branch, UpsellOffer $offer): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($offer->branch_id === $branch->id, 404);

        $request->validate([
            'fee_minor' => 'sometimes|integer|min:0',
            'active' => 'sometimes|boolean',
        ]);

        $rules = is_array($offer->rules) ? $offer->rules : [];

        if ($request->has('fee_minor')) {
            $rules['fee_minor'] = $request->integer('fee_minor');
        }

        $offer->update([
            'rules' => $rules,
            'active' => $request->has('active') ? $request->boolean('active') : $offer->active,
        ]);

        return $this->flashSuccess('Upsell offer updated.');
    }
}
