<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\BusinessDate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessDate>
 */
class BusinessDateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'business_date' => fake()->date(),
            'status' => BusinessDate::STATUS_OPEN,
            'opened_at' => now(),
            'opened_by' => null,
            'closed_at' => null,
            'closed_by' => null,
            'close_summary' => null,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'status' => BusinessDate::STATUS_CLOSED,
            'closed_at' => now(),
        ]);
    }
}
