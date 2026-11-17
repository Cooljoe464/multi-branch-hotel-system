<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\EdgeAgent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EdgeAgent>
 */
class EdgeAgentFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterMaking(function (EdgeAgent $agent): void {
            if ($agent->branch !== null) {
                $agent->tenant_id = $agent->branch->tenant_id;
            }
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'branch_id' => Branch::factory(),
            'name' => fake()->company().' Edge',
            'agent_id' => fake()->uuid(),
            'token_hash' => hash('sha256', fake()->unique()->sha256()),
            'protocol_version' => '1.4',
            'status' => EdgeAgent::STATUS_ACTIVE,
            'last_seen_at' => null,
            'metadata' => null,
        ];
    }
}
