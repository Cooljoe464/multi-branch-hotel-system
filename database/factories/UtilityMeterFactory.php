<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\UtilityMeter;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UtilityMeter> */
class UtilityMeterFactory extends Factory
{
    protected $model = UtilityMeter::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => fake()->randomElement(['Main Meter', 'Sub Meter A', 'Sub Meter B']),
            'meter_number' => fake()->numerify('MTR-######'),
            'type' => fake()->randomElement(['electricity', 'water', 'gas']),
            'tariff_rate_minor' => fake()->numberBetween(50, 200),
            'current_reading' => fake()->randomFloat(2, 1000, 50000),
        ];
    }
}
