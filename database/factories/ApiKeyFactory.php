<?php

namespace Database\Factories;

use App\Models\ApiKey;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiKey>
 */
class ApiKeyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $branch = Branch::factory()->create();
        $secret = fake()->sha256();

        return [
            'tenant_id' => $branch->tenant_id,
            'branch_id' => $branch->id,
            'name' => fake()->words(2, true),
            'type' => 'publishable',
            'environment' => 'test',
            'prefix' => 'hos_test_',
            'key_hash' => hash('sha256', 'hos_test_'.$secret),
            'allowed_origins' => null,
            'ip_allowlist' => null,
            'scopes' => ['availability.view', 'holds.create', 'holds.release'],
            'hold_limit_per_key' => 100,
            'hold_limit_per_ip' => 20,
            'hold_limit_per_session' => 5,
            'revoked_at' => null,
        ];
    }
}
