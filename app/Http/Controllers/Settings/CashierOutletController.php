<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CashierOutletController extends Controller
{
    /**
     * Show the cashier outlet assignments page.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $branchId = (int) $user->branch_id;

        $cashiers = User::whereHas('roles', fn ($q) => $q->where('name', 'Cashier'))
            ->forBranch($branchId)
            ->with('posOutlets')
            ->get()
            ->map(fn ($cashier) => [
                'id' => $cashier->id,
                'name' => $cashier->name,
                'email' => $cashier->email,
                'outlet_ids' => $cashier->posOutlets->pluck('id'),
            ]);

        $outlets = Outlet::forBranch($branchId)
            ->active()
            ->get()
            ->map(fn ($outlet) => [
                'id' => $outlet->id,
                'name' => $outlet->name,
                'code' => $outlet->code,
                'type' => $outlet->type,
            ]);

        return Inertia::render('settings/CashierOutlets', [
            'cashiers' => $cashiers,
            'outlets' => $outlets,
        ]);
    }

    /**
     * Update outlet assignments for a cashier.
     */
    public function update(Request $request, User $cashier): RedirectResponse
    {
        $request->validate([
            'outlet_ids' => 'required|array',
            'outlet_ids.*' => 'exists:outlets,id',
        ]);

        $outletIds = array_values(array_filter($request->array('outlet_ids'), is_int(...)));

        $cashier->posOutlets()->sync($outletIds);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cashier outlet access updated.']);

        return to_route('settings.cashier-outlets.index');
    }
}
