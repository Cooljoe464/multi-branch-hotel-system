<?php

namespace App\Http\Controllers;

use App\Exceptions\AvailabilityException;
use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Services\CostingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GoodsReceiptController extends Controller
{
    use EnsuresBranchAccess;

    public function store(Request $request, Branch $branch, PurchaseOrder $order): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($order->branch_id === $branch->id, 404);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $request->validate([
            'lines_received' => 'required|array|min:1|max:100',
            'lines_received.*.inventory_item_id' => 'required|integer|exists:inventory_items,id',
            'lines_received.*.qty' => 'required|numeric|min:0.01',
            'lines_received.*.unit_cost_minor' => 'required|integer|min:0',
        ]);

        $raw = $request->input('lines_received');
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
                abort_unless($item && $item->branch_id === $branch->id, 422, 'GRN lines must belong to this property.');

                $lines[] = ['inventory_item_id' => $itemId, 'qty' => $qty, 'unit_cost_minor' => $cost];
            }
        }

        if ($lines === []) {
            return back()->withErrors(['lines_received' => 'At least one valid line is required.']);
        }

        $key = $request->header('X-Idempotency-Key');
        $key = is_string($key) && trim($key) !== '' ? trim($key) : null;
        abort_unless($key !== null, 422, 'Goods receipts require an X-Idempotency-Key header.');

        try {
            $receipt = (new CostingService)->receive($order, $lines, $user, $key);
        } catch (AvailabilityException $e) {
            return back()->withErrors(['lines_received' => $e->getMessage()]);
        }

        $status = $order->refresh()->status;

        return $this->flashSuccess("GRN #{$receipt->id} posted; costs revalued, PO {$status}.");
    }
}
