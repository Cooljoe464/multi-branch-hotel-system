<?php

namespace Database\Factories;

use App\Models\ApiKey;
use App\Models\ApiKeyAuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiKeyAuditLog>
 */
class ApiKeyAuditLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'api_key_id' => ApiKey::factory(),
            'actor_id' => null,
            'event' => fake()->randomElement(['created', 'rotated', 'revoked']),
            'metadata' => [],
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ApiKeyAuditLog $log): void {
            $apiKey = $log->apiKey;
            if ($apiKey === null) {
                throw new \LogicException('An API key is required to create its audit log.');
            }

            $log->tenant_id = $apiKey->tenant_id;
            $log->branch_id = $apiKey->branch_id;
        });
    }
}
