<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\MenuItem;
use App\Models\Recipe;
use Illuminate\Database\Seeder;

class RecipeSeeder extends Seeder
{
    public function run(): void
    {
        $branches = Branch::all();

        foreach ($branches as $branch) {
            $foodItems = MenuItem::forBranch($branch->id)->where('category', 'food')->get();
            $drinkItems = MenuItem::forBranch($branch->id)->where('category', 'drink')->get();
            $laundryItems = MenuItem::forBranch($branch->id)->where('category', 'laundry')->get();

            $chicken = InventoryItem::where('branch_id', $branch->id)->where('name', 'Chicken Breast')->first();
            $rice = InventoryItem::where('branch_id', $branch->id)->where('name', 'Rice')->first();
            $oliveOil = InventoryItem::where('branch_id', $branch->id)->where('name', 'Olive Oil')->first();
            $orangeJuice = InventoryItem::where('branch_id', $branch->id)->where('name', 'Orange Juice')->first();
            $coffeeBeans = InventoryItem::where('branch_id', $branch->id)->where('name', 'Coffee Beans')->first();
            $towels = InventoryItem::where('branch_id', $branch->id)->where('name', 'Towels')->first();

            foreach ($foodItems as $index => $item) {
                $ingredientMap = [
                    0 => [['item' => $chicken, 'qty' => 0.5, 'note' => 'Main protein'], ['item' => $oliveOil, 'qty' => 0.1, 'note' => 'For grilling']],
                    1 => [['item' => $rice, 'qty' => 0.3, 'note' => 'Base'], ['item' => $oliveOil, 'qty' => 0.05, 'note' => 'Cooking oil']],
                    2 => [['item' => $chicken, 'qty' => 0.4, 'note' => 'Pasta sauce base'], ['item' => $oliveOil, 'qty' => 0.08, 'note' => 'For sauce']],
                    3 => [['item' => $chicken, 'qty' => 0.6, 'note' => 'Premium cut'], ['item' => $rice, 'qty' => 0.2, 'note' => 'Side dish']],
                    4 => [['item' => $rice, 'qty' => 0.25, 'note' => 'Chips alternative'], ['item' => $oliveOil, 'qty' => 0.1, 'note' => 'For frying']],
                ];

                $ingredients = $ingredientMap[$index % 5] ?? $ingredientMap[0];

                foreach ($ingredients as $recipe) {
                    if (! $recipe['item']) {
                        continue;
                    }

                    Recipe::create([
                        'branch_id' => $branch->id,
                        'menu_item_id' => $item->id,
                        'inventory_item_id' => $recipe['item']->id,
                        'quantity_required' => $recipe['qty'],
                        'notes' => $recipe['note'],
                    ]);
                }
            }

            foreach ($drinkItems as $index => $item) {
                $ingredientMap = [
                    0 => [['item' => $orangeJuice, 'qty' => 0.5, 'note' => 'Fresh squeeze']],
                    1 => [['item' => $coffeeBeans, 'qty' => 0.02, 'note' => 'Double shot']],
                    2 => [['item' => $orangeJuice, 'qty' => 0.3, 'note' => 'Mixer']],
                    3 => [['item' => $orangeJuice, 'qty' => 0.2, 'note' => 'Cocktail base']],
                    4 => [['item' => $orangeJuice, 'qty' => 0.4, 'note' => 'Fresh juice']],
                ];

                $ingredients = $ingredientMap[$index % 5] ?? $ingredientMap[0];

                foreach ($ingredients as $recipe) {
                    if (! $recipe['item']) {
                        continue;
                    }

                    Recipe::create([
                        'branch_id' => $branch->id,
                        'menu_item_id' => $item->id,
                        'inventory_item_id' => $recipe['item']->id,
                        'quantity_required' => $recipe['qty'],
                        'notes' => $recipe['note'],
                    ]);
                }
            }

            foreach ($laundryItems as $index => $item) {
                if (! $towels) {
                    continue;
                }

                $qty = match ($index % 3) {
                    0 => 1.0,
                    1 => 2.0,
                    default => 1.0,
                };

                Recipe::create([
                    'branch_id' => $branch->id,
                    'menu_item_id' => $item->id,
                    'inventory_item_id' => $towels->id,
                    'quantity_required' => $qty,
                    'notes' => 'Linen requirement per service',
                ]);
            }
        }
    }
}
