<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\DailyLedger;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyLedger>
 */
class DailyLedgerFactory extends Factory
{
    protected $model = DailyLedger::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'business_date' => fake()->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
            'status' => 'pending',
            'rooms_posted' => 0,
            'total_room_revenue' => 0,
            'total_tax' => 0,
            'total_other_charges' => 0,
            'total_payments' => 0,
            'net_revenue' => 0,
            'started_at' => null,
            'completed_at' => null,
            'errors' => null,
            'metadata' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => 'pending']);
    }

    public function inProgress(): static
    {
        return $this->state(fn () => [
            'status' => 'in_progress',
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => 'completed',
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
            'rooms_posted' => fake()->numberBetween(5, 50),
            'total_room_revenue' => fake()->numberBetween(50000, 500000),
            'total_tax' => fake()->numberBetween(5000, 50000),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => 'failed',
            'started_at' => now()->subMinutes(5),
            'errors' => [['error' => 'Test error']],
        ]);
    }

    public function forBranch(int $branchId): static
    {
        return $this->state(fn () => ['branch_id' => $branchId]);
    }

    public function forDate(string $date): static
    {
        return $this->state(fn () => ['business_date' => $date]);
    }
}
