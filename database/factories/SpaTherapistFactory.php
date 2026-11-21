<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\SpaTherapist;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SpaTherapist> */
class SpaTherapistFactory extends Factory
{
    protected $model = SpaTherapist::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => fake()->name(),
            'user_id' => null,
            'specialties' => [fake()->randomElement(['deep_tissue', 'swedish', 'hot_stone', 'reflexology'])],
            'is_active' => true,
        ];
    }
}
