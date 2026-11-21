<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\LaundryBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LaundryBatch> */
class LaundryBatchFactory extends Factory
{
    protected $model = LaundryBatch::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'batch_number' => 'LB-'.fake()->unique()->numerify('######'),
            'vendor_type' => 'internal',
            'vendor_name' => null,
            'status' => 'sent',
            'item_counts' => [1 => 20, 2 => 15],
            'dispatched_at' => now(),
            'returned_at' => null,
            'dispatched_by' => User::factory(),
            'received_by' => null,
        ];
    }
}
