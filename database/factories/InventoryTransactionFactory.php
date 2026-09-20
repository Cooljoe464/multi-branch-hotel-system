<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryTransaction>
 */
class InventoryTransactionFactory extends Factory
{
    protected $model = InventoryTransaction::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'inventory_item_id' => InventoryItem::factory(),
            'type' => fake()->randomElement(['deduction', 'restock', 'adjustment']),
            'quantity' => fake()->randomFloat(2, 1, 50),
            'notes' => fake()->sentence(),
            'created_by' => User::factory(),
        ];
    }

    public function deduction(): static
    {
        return $this->state(fn () => ['type' => 'deduction']);
    }

    public function restock(): static
    {
        return $this->state(fn () => ['type' => 'restock']);
    }
}
