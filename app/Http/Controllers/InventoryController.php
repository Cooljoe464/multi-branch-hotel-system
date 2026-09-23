<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Services\InventoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    use Concerns\EnsuresBranchAccess;

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $branchId = (int) $user->branch_id;

        $items = InventoryItem::forBranch($branchId)
            ->when($request->filled('category') && $request->string('category')->value() !== 'all', fn ($q) => $q->forCategory($request->string('category')->value()))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'ilike', '%'.$request->string('search').'%')
                ->orWhere('supplier', 'ilike', '%'.$request->string('search').'%')
            ))
            ->orderBy('category')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('inventory/Index', [
            'inventoryItems' => $items,
            'filters' => $request->only(['category', 'search']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'required|string|in:food,beverage,linen,amenity,equipment',
            'unit' => 'required|string|in:kg,litre,piece',
            'current_quantity' => 'required|numeric|min:0',
            'reorder_point' => 'required|numeric|min:0',
            'cost_per_unit' => 'required|integer|min:0',
            'supplier' => 'nullable|string|max:255',
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $currentBranch = $user->currentBranch;
        abort_unless($currentBranch !== null, 422, 'No active property.');

        InventoryItem::create([
            'branch_id' => $user->branch_id,
            'currency_code' => $currentBranch->currency_code,
            'name' => $request->string('name')->value(),
            'category' => $request->string('category')->value(),
            'unit' => $request->string('unit')->value(),
            'current_quantity' => $request->float('current_quantity'),
            'reorder_point' => $request->float('reorder_point'),
            'cost_per_unit' => $request->integer('cost_per_unit'),
            'supplier' => $request->string('supplier')->value() ?: null,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Inventory item created.']);

        return redirect()->route('inventory.index');
    }

    public function update(Request $request, InventoryItem $inventoryItem): RedirectResponse
    {
        $this->ensureBranchAccess($inventoryItem->branch);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'reorder_point' => 'sometimes|numeric|min:0',
            'cost_per_unit' => 'sometimes|integer|min:0',
            'supplier' => 'nullable|string|max:255',
        ]);

        $inventoryItem->update([
            'name' => $request->input('name'),
            'reorder_point' => $request->input('reorder_point'),
            'cost_per_unit' => $request->input('cost_per_unit'),
            'supplier' => $request->input('supplier'),
        ]);

        return $this->flashSuccess('Inventory item updated.');
    }

    public function restock(Request $request, InventoryItem $inventoryItem): RedirectResponse
    {
        $this->ensureBranchAccess($inventoryItem->branch);

        $request->validate([
            'quantity' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string',
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        (new InventoryService)->restock(
            $inventoryItem,
            $request->float('quantity'),
            $user->id,
            $request->string('notes')->value()
        );

        return $this->flashSuccess('Inventory restocked.');
    }

    public function destroy(InventoryItem $inventoryItem): RedirectResponse
    {
        $this->ensureBranchAccess($inventoryItem->branch);

        $inventoryItem->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Inventory item deleted.']);

        return redirect()->route('inventory.index');
    }
}
