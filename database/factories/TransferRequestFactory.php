<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\TransferRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransferRequest>
 */
class TransferRequestFactory extends Factory
{
    protected $model = TransferRequest::class;

    public function definition(): array
    {
        return [
            'from_branch_id' => Branch::factory(),
            'to_branch_id' => Branch::factory(),
            'requested_by' => User::factory(),
            'status' => 'pending',
            'items' => [
                ['name' => 'Towels', 'quantity' => 10],
                ['name' => 'Sheets', 'quantity' => 5],
            ],
            'notes' => null,
            'approved_at' => null,
            'shipped_at' => null,
            'received_at' => null,
            'metadata' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => 'pending']);
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => 'approved', 'approved_at' => now()]);
    }

    public function inTransit(): static
    {
        return $this->state(fn () => ['status' => 'in_transit', 'approved_at' => now(), 'shipped_at' => now()]);
    }

    public function received(): static
    {
        return $this->state(fn () => ['status' => 'received', 'approved_at' => now(), 'shipped_at' => now(), 'received_at' => now()]);
    }

    public function fromBranch(int $branchId): static
    {
        return $this->state(fn () => ['from_branch_id' => $branchId]);
    }

    public function toBranch(int $branchId): static
    {
        return $this->state(fn () => ['to_branch_id' => $branchId]);
    }
}
