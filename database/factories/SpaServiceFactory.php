<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\SpaService;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SpaService> */
class SpaServiceFactory extends Factory
{
    protected $model = SpaService::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => fake()->words(2, true),
            'category' => fake()->randomElement(['massage', 'facial', 'body_treatment', 'nail_care']),
            'duration_minutes' => fake()->randomElement([30, 60, 90, 120]),
            'price_minor' => fake()->numberBetween(500000, 5000000),
            'is_active' => true,
        ];
    }
}
