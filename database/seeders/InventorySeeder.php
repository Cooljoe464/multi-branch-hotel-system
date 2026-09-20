<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\InventoryItem;
use Illuminate\Database\Seeder;

class InventorySeeder extends Seeder
{
    public function run(): void
    {
        $branches = Branch::all();

        foreach ($branches as $branch) {
            $items = [
                ['name' => 'Chicken Breast', 'category' => 'food', 'unit' => 'kg', 'current_quantity' => 50, 'reorder_point' => 10, 'cost_per_unit' => 2500],
                ['name' => 'Rice', 'category' => 'food', 'unit' => 'kg', 'current_quantity' => 100, 'reorder_point' => 20, 'cost_per_unit' => 800],
                ['name' => 'Olive Oil', 'category' => 'food', 'unit' => 'litre', 'current_quantity' => 20, 'reorder_point' => 5, 'cost_per_unit' => 1500],
                ['name' => 'Orange Juice', 'category' => 'beverage', 'unit' => 'litre', 'current_quantity' => 30, 'reorder_point' => 10, 'cost_per_unit' => 600],
                ['name' => 'Coffee Beans', 'category' => 'beverage', 'unit' => 'kg', 'current_quantity' => 10, 'reorder_point' => 3, 'cost_per_unit' => 5000],
                ['name' => 'Towels', 'category' => 'linen', 'unit' => 'piece', 'current_quantity' => 200, 'reorder_point' => 50, 'cost_per_unit' => 1000],
                ['name' => 'Sheets', 'category' => 'linen', 'unit' => 'piece', 'current_quantity' => 100, 'reorder_point' => 30, 'cost_per_unit' => 2000],
                ['name' => 'Shampoo', 'category' => 'amenity', 'unit' => 'piece', 'current_quantity' => 300, 'reorder_point' => 100, 'cost_per_unit' => 200],
            ];

            foreach ($items as $item) {
                InventoryItem::create(array_merge($item, ['branch_id' => $branch->id]));
            }
        }
    }
}
