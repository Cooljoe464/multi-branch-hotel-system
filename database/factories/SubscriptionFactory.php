<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
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
            'plan_id' => Plan::factory(),
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_ends_at' => now()->addMonth(),
        ];
    }

    public function trialing(): static
    {
        return $this->state(fn (): array => [
            'status' => Subscription::STATUS_TRIALING,
            'trial_ends_at' => now()->addDays(30),
        ]);
    }

    public function pastDue(): static
    {
        return $this->state(fn (): array => [
            'status' => Subscription::STATUS_PAST_DUE,
            'past_due_at' => now()->subDay(),
        ]);
    }

    public function restricted(): static
    {
        return $this->state(fn (): array => [
            'status' => Subscription::STATUS_RESTRICTED,
            'restricted_at' => now()->subDay(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => Subscription::STATUS_CANCELLED,
            'cancelled_at' => now()->subDay(),
        ]);
    }
}
