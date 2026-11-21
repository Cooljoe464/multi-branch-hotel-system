<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\CashierShift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CashierShift> */
class CashierShiftFactory extends Factory
{
    protected $model = CashierShift::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'user_id' => User::factory(),
            'business_date' => now()->toDateString(),
            'opening_float_minor' => 10000,
            'expected_cash_minor' => 10000,
            'counted_cash_minor' => null,
            'variance_minor' => null,
            'status' => CashierShift::STATUS_OPEN,
            'opened_at' => now(),
            'closed_at' => null,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'status' => CashierShift::STATUS_CLOSED,
            'expected_cash_minor' => 10000,
            'counted_cash_minor' => 10000,
            'variance_minor' => 0,
            'closed_at' => now(),
        ]);
    }
}
