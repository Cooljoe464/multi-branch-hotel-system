<?php

namespace App\Http\Controllers;

use App\Models\Outlet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OutletController extends Controller
{
    use Concerns\EnsuresBranchAccess;

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $branchId = (int) $user->branch_id;

        $outlets = Outlet::forBranch($branchId)
            ->with(['kitchenStations'])
            ->when($request->filled('type'), fn ($q) => $q->forType($request->string('type')->value()))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('settings/Outlets', [
            'outlets' => $outlets,
            'filters' => $request->only(['type']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50',
            'type' => 'required|string|in:restaurant,bar,spa,gift_shop,laundry',
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        Outlet::create([
            'branch_id' => $user->branch_id,
            'name' => $request->string('name')->value(),
            'code' => $request->string('code')->value(),
            'type' => $request->string('type')->value(),
            'is_active' => true,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Outlet created.']);

        return redirect()->route('outlets.index');
    }

    public function update(Request $request, Outlet $outlet): RedirectResponse
    {
        $this->ensureBranchAccess($outlet->branch);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'is_active' => 'boolean',
        ]);

        $outlet->update([
            'name' => $request->input('name'),
            'is_active' => $request->boolean('is_active'),
        ]);

        return $this->flashSuccess('Outlet updated.');
    }

    public function destroy(Outlet $outlet): RedirectResponse
    {
        $this->ensureBranchAccess($outlet->branch);

        $outlet->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Outlet deleted.']);

        return redirect()->route('outlets.index');
    }
}
