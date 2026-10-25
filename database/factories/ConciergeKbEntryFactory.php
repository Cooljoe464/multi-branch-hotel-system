<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\ConciergeKbEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConciergeKbEntry>
 */
class ConciergeKbEntryFactory extends Factory
{
    protected $model = ConciergeKbEntry::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'topic' => fake()->words(2, true),
            'question' => fake()->sentence().'?',
            'answer' => fake()->paragraph(),
            'keywords' => [fake()->word(), fake()->word()],
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
