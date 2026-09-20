<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\MenuItem;
use Illuminate\Database\Seeder;

class MenuItemSeeder extends Seeder
{
    public function run(): void
    {
        $branches = Branch::all();

        foreach ($branches as $branch) {
            $items = [
                ['category' => 'food', 'name' => 'Grilled Chicken', 'price' => 4500, 'sort_order' => 1],
                ['category' => 'food', 'name' => 'Caesar Salad', 'price' => 3000, 'sort_order' => 2],
                ['category' => 'food', 'name' => 'Pasta Carbonara', 'price' => 5000, 'sort_order' => 3],
                ['category' => 'food', 'name' => 'Beef Steak', 'price' => 8000, 'sort_order' => 4],
                ['category' => 'food', 'name' => 'Fish & Chips', 'price' => 4000, 'sort_order' => 5],
                ['category' => 'drink', 'name' => 'Fresh Orange Juice', 'price' => 1500, 'sort_order' => 1],
                ['category' => 'drink', 'name' => 'Espresso', 'price' => 800, 'sort_order' => 2],
                ['category' => 'drink', 'name' => 'Sparkling Water', 'price' => 500, 'sort_order' => 3],
                ['category' => 'drink', 'name' => 'House Wine', 'price' => 3500, 'sort_order' => 4],
                ['category' => 'drink', 'name' => 'Cocktail', 'price' => 4000, 'sort_order' => 5],
                ['category' => 'laundry', 'name' => 'Shirt Wash', 'price' => 1000, 'sort_order' => 1],
                ['category' => 'laundry', 'name' => 'Suit Dry Clean', 'price' => 3000, 'sort_order' => 2],
                ['category' => 'laundry', 'name' => 'Towel Exchange', 'price' => 500, 'sort_order' => 3],
                ['category' => 'service', 'name' => 'Spa Treatment', 'price' => 15000, 'sort_order' => 1],
                ['category' => 'service', 'name' => 'Airport Transfer', 'price' => 10000, 'sort_order' => 2],
            ];

            foreach ($items as $item) {
                MenuItem::create(array_merge($item, [
                    'branch_id' => $branch->id,
                    'description' => fake()->sentence(),
                ]));
            }
        }
    }
}
