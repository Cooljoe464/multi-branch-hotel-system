<?php

namespace Database\Factories;

use App\Models\BankProfile;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankProfile>
 */
class BankProfileFactory extends Factory
{
    protected $model = BankProfile::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'bank_name' => fake()->company().' Bank',
            'account_number' => fake()->numerify('############'),
            'account_name' => fake()->words(3, true),
            'swift_code' => fake()->bothify('????####'),
            'sort_code' => fake()->numerify('######'),
            'currency_code' => 'USD',
            'is_default' => false,
            'metadata' => null,
        ];
    }

    public function isDefault(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }

    public function forBranch(int $branchId): static
    {
        return $this->state(fn () => ['branch_id' => $branchId]);
    }
}
