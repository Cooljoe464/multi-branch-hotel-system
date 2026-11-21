<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\LinenDiscard;
use App\Models\LinenItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LinenDiscard> */
class LinenDiscardFactory extends Factory
{
    protected $model = LinenDiscard::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'linen_item_id' => LinenItem::factory(),
            'quantity' => fake()->numberBetween(1, 10),
            'reason' => fake()->randomElement(['torn', 'stained', 'burn', 'worn_out', 'missing']),
            'unit_cost_minor' => 100000,
            'total_loss_minor' => 500000,
            'approved_by' => User::factory(),
            'notes' => null,
        ];
    }
}
