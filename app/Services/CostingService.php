<?php

namespace App\Services;

use App\Events\CostVarianceFlagged;
use App\Events\PurchaseOrderReceived;
use App\Events\StockLow;
use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Models\GoodsReceipt;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\MenuItem;
use App\Models\PosCharge;
use App\Models\PurchaseOrder;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * F&B costing: recipe depletion with yield + wastage, weighted-average
 * valuation on GRN, COGS journaling, and theoretical-vs-actual
 * variance. All money in integer minor units; quantities are floats
 * (kg, litres) with exact minor-unit costs.
 */
class CostingService
{
    /**
     * Deplete one menu item's recipe. Blocks (never negative stock)
     * with a substitute suggestion, journals COGS at weighted cost,
     * and raises StockLow at/below reorder point. Returns COGS minor.
     */
    public function depleteMenuItem(MenuItem $menuItem, int $qty, ?User $by = null): int
    {
        if ($qty < 1) {
            throw new AvailabilityException('COST_QTY', 'Depletion quantity must be positive.');
        }

        return DB::transaction(function () use ($menuItem, $qty, $by) {
            $recipes = Recipe::where('menu_item_id', $menuItem->id)->with('inventoryItem')->get();

            if ($recipes->isEmpty()) {
                return 0;
            }

            $branch = Branch::findOrFail($menuItem->branch_id);
            $businessDate = (new BusinessDateService)->current($branch)->business_date->toDateString();
            $cogs = 0;

            foreach ($recipes as $recipe) {
                $item = $recipe->inventoryItem;

                if (! $item) {
                    continue;
                }

                $locked = InventoryItem::where('id', $item->id)->lockForUpdate()->firstOrFail();
                $required = $this->requiredQty($recipe, $qty);

                if ($locked->current_quantity < $required) {
                    throw new AvailabilityException(
                        'STOCK_OUT',
                        "Insufficient {$locked->name} for {$menuItem->name}.".($this->substituteFor($menuItem) ?? '')
                    );
                }

                $locked->update(['current_quantity' => $locked->current_quantity - $required]);

                InventoryTransaction::create([
                    'branch_id' => $locked->branch_id,
                    'inventory_item_id' => $locked->id,
                    'type' => 'deduction',
                    'quantity' => $required,
                    'created_by' => $by?->id,
                    'notes' => "Depleted for {$qty}x {$menuItem->name}",
                ]);

                $lineCost = (int) round($required * $locked->cost_per_unit);
                $cogs += $lineCost;

                if ($lineCost > 0) {
                    // No journal key: each sale is a distinct line; the
                    // surrounding transaction is the atomicity boundary.
                    (new PostingService(new JournalService))->post(
                        branch: $branch,
                        businessDate: $businessDate,
                        event: 'cogs.recognized',
                        amountMinor: $lineCost,
                        source: $locked,
                        createdBy: $by,
                    );
                }

                if ($locked->current_quantity <= $locked->reorder_point) {
                    event(new StockLow($locked->fresh() ?? $locked));
                }
            }

            return $cogs;
        });
    }

    /**
     * Receive a PO. Idempotent on the key: replays return the original
     * receipt without restocking or journaling twice.
     *
     * @param  list<array<string, mixed>>  $linesReceived  {inventory_item_id, qty, unit_cost_minor}.
     */
    public function receive(PurchaseOrder $po, array $linesReceived, User $by, string $idempotencyKey): GoodsReceipt
    {
        return DB::transaction(function () use ($po, $linesReceived, $by, $idempotencyKey) {
            // Replay first: an already-received key returns its receipt
            // even though the PO has since flipped to received.
            $replay = GoodsReceipt::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();

            if ($replay) {
                return $replay;
            }

            $locked = PurchaseOrder::where('id', $po->id)->lockForUpdate()->firstOrFail();

            if (! $locked->receivable()) {
                throw new AvailabilityException('PO_STATE', "PO #{$locked->id} is {$locked->status}.");
            }

            $branch = Branch::findOrFail($locked->branch_id);
            $businessDate = (new BusinessDateService)->current($branch)->business_date->toDateString();

            try {
                $receipt = GoodsReceipt::create([
                    'branch_id' => $locked->branch_id,
                    'purchase_order_id' => $locked->id,
                    'lines_received' => [],
                    'received_by' => $by->id,
                    'idempotency_key' => $idempotencyKey,
                ]);
            } catch (QueryException $e) {
                // Lost a creation race: the winner's receipt is the GRN.
                if ($this->isUniqueViolation($e)) {
                    return GoodsReceipt::where('idempotency_key', $idempotencyKey)->firstOrFail();
                }

                throw $e;
            }

            $received = [];
            foreach ($linesReceived as $line) {
                $itemId = $line['inventory_item_id'] ?? null;
                $qty = $line['qty'] ?? null;
                $unitCost = $line['unit_cost_minor'] ?? null;

                if (! is_int($itemId) || (! is_int($qty) && ! is_float($qty)) || ! is_int($unitCost)) {
                    continue;
                }

                if ($qty <= 0 || $unitCost < 0) {
                    continue;
                }

                $item = InventoryItem::where('id', $itemId)->lockForUpdate()->firstOrFail();

                if ($item->branch_id !== $locked->branch_id) {
                    throw new AvailabilityException('PO_BRANCH', 'GRN lines must belong to the PO property.');
                }

                $oldQty = $item->current_quantity;
                $newQty = $oldQty + $qty;
                $newCost = $newQty > 0
                    ? (int) round(($oldQty * $item->cost_per_unit + $qty * $unitCost) / $newQty)
                    : $unitCost;

                $item->update(['current_quantity' => $newQty, 'cost_per_unit' => $newCost]);

                InventoryTransaction::create([
                    'branch_id' => $item->branch_id,
                    'inventory_item_id' => $item->id,
                    'type' => 'restock',
                    'quantity' => $qty,
                    'created_by' => $by->id,
                    'notes' => "GRN for PO #{$locked->id}",
                ]);

                $lineTotal = (int) round($qty * $unitCost);

                if ($lineTotal > 0) {
                    (new PostingService(new JournalService))->post(
                        branch: $branch,
                        businessDate: $businessDate,
                        event: 'inventory.received',
                        amountMinor: $lineTotal,
                        source: $item,
                        createdBy: $by,
                    );
                }

                $received[] = ['inventory_item_id' => $item->id, 'qty' => $qty, 'unit_cost_minor' => $unitCost];
            }

            $receipt->update(['lines_received' => $received]);
            $locked->update(['status' => $this->receiptStatus($locked, $received)]);

            event(new PurchaseOrderReceived($locked->fresh() ?? $locked, $receipt->fresh() ?? $receipt));

            return $receipt->fresh() ?? $receipt;
        });
    }

    /**
     * Theoretical (recipes × posted sales) vs actual (deduction
     * postings) per ingredient. Flags rows whose absolute variance
     * exceeds the threshold share of theoretical.
     *
     * @return array{from: string, to: string, rows: list<array{inventory_item_id: int, name: string, unit: string, theoretical_qty: float, actual_qty: float, variance_qty: float, cost_value_minor: int, flagged: bool}>}
     */
    public function variance(Branch $branch, string $from, string $to, ?float $thresholdPct = null): array
    {
        $threshold = $thresholdPct ?? $this->varianceThreshold($branch);
        $sold = [];

        $charges = PosCharge::forBranch($branch->id)
            ->posted()
            ->whereBetween('business_date', [$from, $to])
            ->get(['items']);

        foreach ($charges as $charge) {
            foreach ($charge->items as $line) {
                $menuItemId = $line['menu_item_id'] ?? null;
                $qty = $line['quantity'] ?? 0;

                if (! is_int($menuItemId) || (! is_int($qty) && ! is_float($qty))) {
                    continue;
                }

                $sold[$menuItemId] = ($sold[$menuItemId] ?? 0) + $qty;
            }
        }

        $theoretical = [];
        foreach (Recipe::where('branch_id', $branch->id)->with('inventoryItem')->get() as $recipe) {
            $qty = $sold[$recipe->menu_item_id] ?? 0;

            if ($qty <= 0 || ! $recipe->inventoryItem) {
                continue;
            }

            $itemId = $recipe->inventory_item_id;
            $theoretical[$itemId] = ($theoretical[$itemId] ?? 0) + $this->requiredQty($recipe, $qty);
        }

        $actual = [];
        foreach (InventoryTransaction::where('branch_id', $branch->id)
            ->where('type', 'deduction')
            ->whereBetween('created_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])
            ->get(['inventory_item_id', 'quantity']) as $txn) {
            $actual[$txn->inventory_item_id] = ($actual[$txn->inventory_item_id] ?? 0) + (float) $txn->quantity;
        }

        $rows = [];
        $flagged = [];
        foreach (InventoryItem::forBranch($branch->id)->get() as $item) {
            $expected = $theoretical[$item->id] ?? 0.0;
            $used = $actual[$item->id] ?? 0.0;

            if ($expected <= 0 && $used <= 0) {
                continue;
            }

            $variance = $used - $expected;
            $isFlagged = $expected > 0 && abs($variance) > $expected * $threshold / 100;

            $row = [
                'inventory_item_id' => $item->id,
                'name' => $item->name,
                'unit' => $item->unit,
                'theoretical_qty' => round($expected, 4),
                'actual_qty' => round($used, 4),
                'variance_qty' => round($variance, 4),
                'cost_value_minor' => (int) round(abs($variance) * $item->cost_per_unit),
                'flagged' => $isFlagged,
            ];
            $rows[] = $row;

            if ($isFlagged) {
                $flagged[] = $row;
            }
        }

        if ($flagged !== []) {
            event(new CostVarianceFlagged($branch, $flagged));
        }

        return ['from' => $from, 'to' => $to, 'rows' => $rows];
    }

    /**
     * Required quantity for a sale: per-portion need scaled by count
     * with wastage on top. Yield of ≤0 means one portion per unit.
     */
    private function requiredQty(Recipe $recipe, float|int $count): float
    {
        $yield = max(1, $recipe->yield_qty);
        $perPortion = $recipe->quantity_required / $yield;

        return $perPortion * $count * (10000 + $recipe->wastage_bps) / 10000;
    }

    private function substituteFor(MenuItem $menuItem): ?string
    {
        $candidate = MenuItem::where('branch_id', $menuItem->branch_id)
            ->where('id', '!=', $menuItem->id)
            ->where('category', $menuItem->category)
            ->where('is_available', true)
            ->where('is_active', true)
            ->first();

        return $candidate ? " Suggest {$candidate->name} instead." : null;
    }

    /**
     * @param  list<array<string, mixed>>  $received
     */
    private function receiptStatus(PurchaseOrder $po, array $received): string
    {
        $ordered = [];
        foreach ($po->lines as $line) {
            $id = $line['inventory_item_id'] ?? null;
            $qty = $line['qty'] ?? 0;

            if (is_int($id) && (is_int($qty) || is_float($qty))) {
                $ordered[$id] = ($ordered[$id] ?? 0) + $qty;
            }
        }

        $gotten = [];
        foreach ($po->receipts()->get(['lines_received']) as $receipt) {
            foreach ($receipt->lines_received as $line) {
                $id = $line['inventory_item_id'] ?? null;
                $qty = $line['qty'] ?? 0;

                if (is_int($id) && (is_int($qty) || is_float($qty))) {
                    $gotten[$id] = ($gotten[$id] ?? 0) + $qty;
                }
            }
        }

        foreach ($received as $line) {
            $id = $line['inventory_item_id'] ?? null;
            $qty = $line['qty'] ?? 0;

            if (is_int($id) && (is_int($qty) || is_float($qty))) {
                $gotten[$id] = ($gotten[$id] ?? 0) + $qty;
            }
        }

        foreach ($ordered as $id => $qty) {
            if (($gotten[$id] ?? 0) < $qty) {
                return PurchaseOrder::STATUS_PARTIAL;
            }
        }

        return PurchaseOrder::STATUS_RECEIVED;
    }

    private function varianceThreshold(Branch $branch): float
    {
        $settings = $branch->settings;
        $value = is_array($settings) ? ($settings['cost_variance_threshold_pct'] ?? null) : null;

        return is_numeric($value) ? (float) $value : 5.0;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $code = $e->getPrevious()?->getCode();

        return $code === '23000' || $code === '23505';
    }
}
