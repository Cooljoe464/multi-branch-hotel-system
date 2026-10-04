<?php

namespace Database\Factories;

use App\Models\AuditFlag;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditFlag>
 */
class AuditFlagFactory extends Factory
{
    protected $model = AuditFlag::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'flag_type' => fake()->randomElement(['rate_override_anomaly', 'cash_drawer', 'keycard_replacement', 'voided_charge']),
            'severity' => fake()->randomElement(['low', 'medium', 'high', 'critical']),
            'subject_type' => null,
            'subject_id' => null,
            'description' => fake()->sentence(),
            'evidence' => null,
            'is_reviewed' => false,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'metadata' => null,
        ];
    }


    public function unreviewed(): static
    {
        return $this->state(fn () => ['is_reviewed' => false]);
    }

    public function reviewed(): static
    {
        return $this->state(fn () => ['is_reviewed' => true, 'reviewed_at' => now()]);
    }

    public function critical(): static
    {
        return $this->state(fn () => ['severity' => 'critical']);
    }

    public function forBranch(int $branchId): static
    {
        return $this->state(fn () => ['branch_id' => $branchId]);
    }
}
