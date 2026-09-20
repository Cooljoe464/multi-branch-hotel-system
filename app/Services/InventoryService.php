<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\MenuItem;
use App\Models\Recipe;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    public function deductIngredients(MenuItem $menuItem, int $quantity = 1): void
    {
        $recipes = Recipe::where('menu_item_id', $menuItem->id)->get();

        foreach ($recipes as $recipe) {
            $item = $recipe->inventoryItem;

            if (! $item) {
                continue;
            }

            $totalDeduction = $recipe->quantity_required * $quantity;

            DB::transaction(function () use ($recipe, $item, $totalDeduction) {
                $locked = InventoryItem::where('id', $item->id)->lockForUpdate()->first();

                if (! $locked) {
                    throw new \RuntimeException("Inventory item not found: {$item->id}");
                }

                $locked->update(['current_quantity' => max(0, $locked->current_quantity - $totalDeduction)]);

                InventoryTransaction::create([
                    'branch_id' => $locked->branch_id,
                    'inventory_item_id' => $locked->id,
                    'type' => 'deduction',
                    'quantity' => $totalDeduction,
                    'notes' => "Auto-deducted for menu item: {$recipe->menuItem->name}",
                ]);
            });
        }
    }

    public function restock(InventoryItem $item, float $quantity, ?int $userId = null, ?string $notes = null): void
    {
        DB::transaction(function () use ($item, $quantity, $userId, $notes) {
            $locked = InventoryItem::where('id', $item->id)->lockForUpdate()->first();

            if (! $locked) {
                throw new \RuntimeException("Inventory item not found: {$item->id}");
            }

            $locked->update(['current_quantity' => $locked->current_quantity + $quantity]);

            InventoryTransaction::create([
                'branch_id' => $locked->branch_id,
                'inventory_item_id' => $locked->id,
                'type' => 'restock',
                'quantity' => $quantity,
                'created_by' => $userId,
                'notes' => $notes,
            ]);
        });
    }

    public function adjustStock(InventoryItem $item, float $newQuantity, ?int $userId = null, ?string $notes = null): void
    {
        $difference = $newQuantity - $item->current_quantity;

        DB::transaction(function () use ($item, $newQuantity, $difference, $userId, $notes) {
            $locked = InventoryItem::where('id', $item->id)->lockForUpdate()->first();

            if (! $locked) {
                throw new \RuntimeException("Inventory item not found: {$item->id}");
            }

            $locked->update(['current_quantity' => $newQuantity]);

            InventoryTransaction::create([
                'branch_id' => $locked->branch_id,
                'inventory_item_id' => $locked->id,
                'type' => 'adjustment',
                'quantity' => abs($difference),
                'created_by' => $userId,
                'notes' => $notes ?? "Adjusted from {$item->current_quantity} to {$newQuantity}",
            ]);
        });
    }
}
