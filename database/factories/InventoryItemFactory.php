<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\InventoryItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryItem>
 */
class InventoryItemFactory extends Factory
{
    protected $model = InventoryItem::class;

    public function definition(): array
    {
        $category = fake()->randomElement(['food', 'beverage', 'linen', 'amenity', 'equipment']);

        return [
            'branch_id' => Branch::factory(),
            'name' => fake()->words(2, true),
            'category' => $category,
            'unit' => fake()->randomElement(['kg', 'litre', 'piece']),
            'current_quantity' => fake()->randomFloat(2, 0, 100),
            'reorder_point' => fake()->randomFloat(2, 5, 20),
            'cost_per_unit' => fake()->numberBetween(100, 5000),
            'supplier' => fake()->company(),
            'metadata' => null,
        ];
    }

    public function food(): static
    {
        return $this->state(fn () => ['category' => 'food']);
    }

    public function beverage(): static
    {
        return $this->state(fn () => ['category' => 'beverage']);
    }

    public function belowReorderPoint(): static
    {
        return $this->state(fn () => ['current_quantity' => 2, 'reorder_point' => 10]);
    }

    public function forBranch(int $branchId): static
    {
        return $this->state(fn () => ['branch_id' => $branchId]);
    }
}
