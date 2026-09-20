<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\KotItem;
use App\Models\PosCharge;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KotItem>
 */
class KotItemFactory extends Factory
{
    protected $model = KotItem::class;

    public function definition(): array
    {
        return [
            'pos_charge_id' => PosCharge::factory(),
            'branch_id' => Branch::factory(),
            'outlet' => fake()->randomElement(['restaurant', 'bar', 'pool_bar', 'room_service']),
            'item_name' => fake()->randomElement(['Grilled Chicken', 'Pasta Carbonara', 'Caesar Salad', 'Club Sandwich', 'Margherita Pizza']),
            'quantity' => fake()->numberBetween(1, 5),
            'status' => 'pending',
            'notes' => fake()->optional()->sentence(),
            'priority' => fake()->randomElement(['normal', 'rush']),
            'prepared_at' => null,
            'served_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => 'pending']);
    }

    public function preparing(): static
    {
        return $this->state(fn () => ['status' => 'preparing']);
    }

    public function ready(): static
    {
        return $this->state(fn () => [
            'status' => 'ready',
            'prepared_at' => now(),
        ]);
    }

    public function served(): static
    {
        return $this->state(fn () => [
            'status' => 'served',
            'prepared_at' => now()->subMinutes(15),
            'served_at' => now(),
        ]);
    }

    public function rush(): static
    {
        return $this->state(fn () => ['priority' => 'rush']);
    }

    public function forBranch(int $branchId): static
    {
        return $this->state(fn () => ['branch_id' => $branchId]);
    }
}
