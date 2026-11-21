<?php

namespace Database\Factories;

use App\Models\CashDrop;
use App\Models\CashierShift;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CashDrop> */
class CashDropFactory extends Factory
{
    protected $model = CashDrop::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'cashier_shift_id' => CashierShift::factory(),
            'amount_minor' => fake()->numberBetween(10000, 500000),
            'envelope_number' => 'ENV-'.fake()->unique()->numerify('####'),
            'witness_user_id' => null,
            'dropped_at' => now(),
            'notes' => null,
        ];
    }
}
