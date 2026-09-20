<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\GroupLedger;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupLedger>
 */
class GroupLedgerFactory extends Factory
{
    protected $model = GroupLedger::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'business_date' => fake()->date(),
            'total_room_revenue' => fake()->randomFloat(2, 10000, 500000),
            'total_pos_revenue' => fake()->randomFloat(2, 1000, 100000),
            'total_tax' => fake()->randomFloat(2, 500, 50000),
            'total_payments' => fake()->randomFloat(2, 10000, 500000),
            'net_revenue' => fake()->randomFloat(2, 5000, 400000),
            'currency_code' => 'USD',
            'exchange_rate_to_group' => 1.000000,
            'metadata' => null,
        ];
    }

    public function forBranch(int $branchId): static
    {
        return $this->state(fn () => ['branch_id' => $branchId]);
    }
}
