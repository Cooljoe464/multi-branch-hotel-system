<?php

namespace Database\Factories;

use App\Models\IdempotencyKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IdempotencyKey>
 */
class IdempotencyKeyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'branch_id' => null,
            'scope' => 'test.scope',
            'key' => fake()->uuid(),
            'status' => IdempotencyKey::STATUS_COMPLETED,
            'request_hash' => null,
            'response' => null,
            'locked_until' => null,
        ];
    }
}
