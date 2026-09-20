<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\ChannelProviderModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChannelProviderModel>
 */
class ChannelProviderFactory extends Factory
{
    protected $model = ChannelProviderModel::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'provider' => 'booking_com',
            'api_key' => fake()->bothify('????????????????'),
            'api_secret' => fake()->bothify('????????????????????'),
            'property_id_external' => fake()->numerify('######'),
            'is_active' => true,
            'settings' => null,
            'last_sync_at' => null,
            'metadata' => null,
        ];
    }

    public function bookingCom(): static
    {
        return $this->state(fn () => ['provider' => 'booking_com']);
    }

    public function forBranch(int $branchId): static
    {
        return $this->state(fn () => ['branch_id' => $branchId]);
    }
}
