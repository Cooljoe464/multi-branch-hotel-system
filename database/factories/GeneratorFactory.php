<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Generator;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Generator> */
class GeneratorFactory extends Factory
{
    protected $model = Generator::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => 'Gen-'.fake()->numberBetween(1, 10),
            'model' => fake()->randomElement(['CAT C15', 'Perkins 4008', 'Mikano 500']),
            'kva_rating' => fake()->randomElement([100, 250, 500, 1000]),
            'fuel_capacity_liters' => fake()->randomElement([500.0, 1000.0, 2000.0]),
            'liters_per_hour_rated' => fake()->randomFloat(1, 5, 50),
            'current_meter_hours' => fake()->randomFloat(1, 100, 10000),
            'status' => 'idle',
            'last_serviced_at' => fake()->dateTimeBetween('-6 months', 'now'),
        ];
    }
}
