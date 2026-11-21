<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\LinenItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LinenItem> */
class LinenItemFactory extends Factory
{
    protected $model = LinenItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => fake()->randomElement(['King Sheet Set', 'Bath Towel', 'Hand Towel', 'Pillow Case', 'Duvet Cover', 'Table Cloth']),
            'category' => fake()->randomElement(['bed_linen', 'bath_linen', 'table_linen', 'uniforms']),
            'par_level' => fake()->numberBetween(50, 200),
            'current_clean' => fake()->numberBetween(20, 100),
            'current_dirty' => fake()->numberBetween(0, 30),
            'current_in_laundry' => fake()->numberBetween(0, 20),
            'unit_cost_minor' => fake()->numberBetween(50000, 500000),
        ];
    }
}
