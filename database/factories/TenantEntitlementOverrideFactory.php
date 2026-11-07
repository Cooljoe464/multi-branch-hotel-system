<?php

namespace Database\Factories;

use App\Models\PlanEntitlement;
use App\Models\Tenant;
use App\Models\TenantEntitlementOverride;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantEntitlementOverride>
 */
class TenantEntitlementOverrideFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'entitlement_key' => 'pos.sales',
            'value_type' => PlanEntitlement::TYPE_BOOLEAN,
            'value' => 'true',
            'reason' => fake()->sentence(),
            'expires_at' => now()->addMonth(),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subDay()]);
    }
}
