<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\CostingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderController extends Controller
{
    use EnsuresBranchAccess;

    public function index(Branch $branch): Response
    {
        $this->ensureBranchAccess($branch);

        return Inertia::render('inventory/Costing', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'suppliers' => Supplier::forBranch($branch->id)->orderBy('name')->get(),
            'orders' => PurchaseOrder::forBranch($branch->id)
                ->with(['supplier', 'receipts'])
                ->orderByDesc('id')
                ->paginate(25)
                ->withQueryString(),
            'items' => InventoryItem::forBranch($branch->id)->orderBy('name')->get(['id', 'name', 'unit', 'current_quantity', 'cost_per_unit']),
        ]);
    }

    public function store(Request $request, Branch $branch): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'lines' => 'required|array|min:1|max:100',
            'lines.*.inventory_item_id' => 'required|integer|exists:inventory_items,id',
            'lines.*.qty' => 'required|numeric|min:0.01',
            'lines.*.unit_cost_minor' => 'required|integer|min:0',
        ]);

        $supplier = Supplier::findOrFail($request->integer('supplier_id'));
        abort_unless($supplier->branch_id === $branch->id, 403);

        $raw = $request->input('lines');
        $lines = [];
        if (is_array($raw)) {
            foreach ($raw as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $itemId = $row['inventory_item_id'] ?? null;
                $qty = $row['qty'] ?? null;
                $cost = $row['unit_cost_minor'] ?? null;

                if (! is_int($itemId) || (! is_int($qty) && ! is_float($qty)) || ! is_int($cost)) {
                    continue;
                }

                $item = InventoryItem::find($itemId);
                abort_unless($item && $item->branch_id === $branch->id, 422, 'PO lines must belong to this property.');

                $lines[] = ['inventory_item_id' => $itemId, 'qty' => $qty, 'unit_cost_minor' => $cost];
            }
        }

        if ($lines === []) {
            return back()->withErrors(['lines' => 'At least one valid line is required.']);
        }

        PurchaseOrder::create([
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'lines' => $lines,
            'status' => PurchaseOrder::STATUS_DRAFT,
            'created_by' => $request->user()?->id,
        ]);

        return $this->flashSuccess('Purchase order drafted.');
    }

    public function send(Branch $branch, PurchaseOrder $order): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($order->branch_id === $branch->id, 404);

        if ($order->status !== PurchaseOrder::STATUS_DRAFT) {
            return back()->withErrors(['order' => 'Only draft orders can be sent.']);
        }

        $order->update(['status' => PurchaseOrder::STATUS_SENT]);

        return $this->flashSuccess("PO #{$order->id} sent to {$order->supplier->name}.");
    }

    public function cancel(Branch $branch, PurchaseOrder $order): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($order->branch_id === $branch->id, 404);

        if (in_array($order->status, [PurchaseOrder::STATUS_RECEIVED, PurchaseOrder::STATUS_CANCELLED], true)) {
            return back()->withErrors(['order' => 'Received or cancelled orders cannot be cancelled.']);
        }

        $order->update(['status' => PurchaseOrder::STATUS_CANCELLED]);

        return $this->flashSuccess("PO #{$order->id} cancelled.");
    }

    public function variance(Request $request, Branch $branch): Response
    {
        $this->ensureBranchAccess($branch);

        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $from = $request->string('from')->value() !== '' ? $request->string('from')->value() : now()->subDays(7)->toDateString();
        $to = $request->string('to')->value() !== '' ? $request->string('to')->value() : now()->toDateString();

        return Inertia::render('inventory/Variance', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'filters' => ['from' => $from, 'to' => $to],
            'report' => (new CostingService)->variance($branch, $from, $to),
        ]);
    }
}
