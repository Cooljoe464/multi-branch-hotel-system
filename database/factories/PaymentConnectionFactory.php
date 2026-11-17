<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\PaymentConnection;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentConnection>
 */
class PaymentConnectionFactory extends Factory
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
            'branch_id' => Branch::factory(),
            'provider' => fake()->randomElement(PaymentConnection::PROVIDERS),
            'mode' => 'test',
            'name' => fake()->company().' checkout',
            'secret_key' => fake()->password(32),
            'webhook_secret' => fake()->password(32),
            'merchant_reference' => fake()->uuid(),
            'is_default' => true,
            'status' => 'active',
        ];
    }

    public function forBranch(Branch $branch): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $branch->tenant_id,
            'branch_id' => $branch->id,
        ]);
    }
}
