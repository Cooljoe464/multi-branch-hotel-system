<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\KitchenWasteLog;
use App\Models\MenuItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KitchenWasteLog>
 */
class KitchenWasteLogFactory extends Factory
{
    protected $model = KitchenWasteLog::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'menu_item_id' => MenuItem::factory(),
            'kot_item_id' => null,
            'reason' => fake()->randomElement(['expired', 'burned', 'returned', 'overproduced']),
            'quantity' => fake()->numberBetween(1, 10),
            'cost' => fake()->numberBetween(100, 5000),
            'notes' => null,
            'logged_by' => User::factory(),
        ];
    }

    public function forBranch(int $branchId): static
    {
        return $this->state(fn () => ['branch_id' => $branchId]);
    }
}
