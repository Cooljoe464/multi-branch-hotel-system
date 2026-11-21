<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\FuelLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FuelLog> */
class FuelLogFactory extends Factory
{
    protected $model = FuelLog::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $liters = fake()->randomFloat(1, 50, 500);
        $costPerLiter = fake()->numberBetween(800, 1200);

        return [
            'branch_id' => Branch::factory(),
            'generator_id' => null,
            'log_type' => 'delivery',
            'liters' => $liters,
            'cost_per_liter_minor' => $costPerLiter,
            'total_cost_minor' => (int) ($liters * $costPerLiter),
            'supplier_name' => fake()->company(),
            'invoice_number' => 'INV-'.fake()->numerify('######'),
            'dipstick_level_cm' => fake()->randomFloat(1, 10, 100),
            'logged_by' => User::factory(),
            'logged_at' => now(),
        ];
    }
}
