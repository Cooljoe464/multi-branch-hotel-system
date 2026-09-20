<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\CityLedgerAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CityLedgerAccount>
 */
class CityLedgerAccountFactory extends Factory
{
    protected $model = CityLedgerAccount::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'company_name' => fake()->company(),
            'contact_name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'credit_limit' => fake()->numberBetween(100000, 1000000),
            'balance_owing' => 0,
            'payment_terms_days' => fake()->randomElement([15, 30, 45, 60]),
            'is_active' => true,
            'metadata' => null,
        ];
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
