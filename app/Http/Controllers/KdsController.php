<?php

namespace App\Http\Controllers;

use App\Models\KotItem;
use App\Models\MenuItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class KdsController extends Controller
{
    use Concerns\EnsuresBranchAccess;

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $branchId = (int) $user->branch_id;

        $items = KotItem::forBranch($branchId)
            ->with(['posCharge.reservation'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->value()))
            ->when($request->filled('outlet'), fn ($q) => $q->forOutlet($request->string('outlet')->value()))
            ->orderByDesc('priority')
            ->orderBy('created_at')
            ->get();

        return Inertia::render('kds/Index', [
            'kotItems' => $items,
            'filters' => $request->only(['status', 'outlet']),
        ]);
    }

    public function updateStatus(Request $request, KotItem $kotItem): RedirectResponse
    {
        $this->ensureBranchAccess($kotItem->branch);

        $request->validate([
            'status' => 'required|string|in:preparing,ready,served,cancelled',
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $status = $request->string('status')->value();

        match ($status) {
            'preparing' => $kotItem->markPreparing(),
            'ready' => $kotItem->markReady(),
            'served' => $kotItem->markServed(),
            'cancelled' => $kotItem->cancel(),
            default => abort(422, "Invalid status: {$status}"),
        };

        return $this->flashSuccess("KOT item marked as {$status}.");
    }

    public function toggleStock(Request $request, MenuItem $menuItem): RedirectResponse
    {
        $this->ensureBranchAccess($menuItem->branch);

        $menuItem->update(['is_available' => ! $menuItem->is_available]);

        return $this->flashSuccess("{$menuItem->name} is now ".($menuItem->is_available ? 'available' : 'out of stock').'.');
    }
}
