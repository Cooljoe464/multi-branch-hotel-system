<?php

namespace App\Http\Controllers;

use App\Models\KitchenWasteLog;
use App\Models\MenuItem;
use App\Services\KitchenWasteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class KitchenWasteController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $branchId = (int) $user->branch_id;

        $wasteLogs = KitchenWasteLog::where('branch_id', $branchId)
            ->with(['menuItem', 'loggedByUser'])
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->string('from')->value()))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->string('to')->value()))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        $menuItems = MenuItem::where('branch_id', $branchId)
            ->whereIn('category', ['food', 'drink'])
            ->orderBy('name')
            ->get();

        return Inertia::render('kitchen/Waste', [
            'wasteLogs' => $wasteLogs,
            'menuItems' => $menuItems,
            'filters' => $request->only(['from', 'to']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'menu_item_id' => 'required|exists:menu_items,id',
            'reason' => 'required|string|in:expired,burned,returned,overproduced',
            'quantity' => 'required|integer|min:1',
            'cost' => 'required|integer|min:0',
            'notes' => 'nullable|string',
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $wasteService = new KitchenWasteService;
        $wasteService->logWaste(
            (int) $user->branch_id,
            $request->integer('menu_item_id'),
            $request->string('reason')->value(),
            $request->integer('quantity'),
            $request->integer('cost'),
            $user->id,
            $request->string('notes')->value() ?: null,
        );

        return $this->flashSuccess('Waste logged.');
    }

    public function report(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $branchId = (int) $user->branch_id;

        $query = KitchenWasteLog::where('branch_id', $branchId);

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->string('from')->value());
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->string('to')->value());
        }

        $report = (clone $query)
            ->select('reason', \DB::raw('SUM(quantity) as total_quantity'), \DB::raw('SUM(cost) as total_cost'))
            ->groupBy('reason')
            ->get();

        return Inertia::render('kitchen/WasteReport', [
            'wasteReport' => $report,
            'filters' => $request->only(['from', 'to']),
        ]);
    }
}
