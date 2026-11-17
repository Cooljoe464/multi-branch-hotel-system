<?php

namespace Database\Factories;

use App\Models\ApiKey;
use App\Models\ApiKeyDailyUsage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiKeyDailyUsage>
 */
class ApiKeyDailyUsageFactory extends Factory
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
            'usage_date' => now()->toDateString(),
            'request_count' => fake()->numberBetween(1, 100),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ApiKeyDailyUsage $usage): void {
            $apiKey = $usage->apiKey;
            if ($apiKey === null) {
                throw new \LogicException('An API key is required to create daily usage.');
            }

            $usage->tenant_id = $apiKey->tenant_id;
            $usage->branch_id = $apiKey->branch_id;
        });
    }
}
