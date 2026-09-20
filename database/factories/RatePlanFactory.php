<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\RatePlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RatePlan>
 */
class RatePlanFactory extends Factory
{
    protected $model = RatePlan::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'room_type_id' => null,
            'name' => fake()->words(2, true),
            'code' => strtoupper(fake()->bothify('??-####')),
            'type' => fake()->randomElement(['bar', 'corporate', 'package', 'promotional']),
            'rate_multiplier' => fake()->randomFloat(2, 0.8, 1.5),
            'is_negotiable' => fake()->boolean(20),
            'min_rate' => null,
            'max_rate' => null,
            'valid_from' => fake()->date(),
            'valid_to' => null,
            'is_active' => true,
            'metadata' => null,
        ];
    }

    public function bar(): static
    {
        return $this->state(fn () => ['type' => 'bar', 'name' => 'Best Available Rate', 'code' => 'BAR']);
    }

    public function corporate(): static
    {
        return $this->state(fn () => ['type' => 'corporate', 'rate_multiplier' => 0.85]);
    }

    public function package(): static
    {
        return $this->state(fn () => ['type' => 'package', 'rate_multiplier' => 1.2]);
    }

    public function promotional(): static
    {
        return $this->state(fn () => ['type' => 'promotional', 'rate_multiplier' => 0.75]);
    }

    public function active(): static
    {
        return $this->state(fn () => ['is_active' => true]);
    }

    public function forBranch(int $branchId): static
    {
        return $this->state(fn () => ['branch_id' => $branchId]);
    }
}
