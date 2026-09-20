<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\MenuItem;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Recipe>
 */
class RecipeFactory extends Factory
{
    protected $model = Recipe::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'menu_item_id' => MenuItem::factory(),
            'inventory_item_id' => InventoryItem::factory(),
            'quantity_required' => fake()->randomFloat(4, 0.1, 5),
            'notes' => null,
        ];
    }
}
