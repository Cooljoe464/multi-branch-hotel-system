<?php

namespace Database\Factories;

use App\Models\ChannelProviderModel;
use App\Models\ChannelRate;
use App\Models\RatePlan;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChannelRate>
 */
class ChannelRateFactory extends Factory
{
    protected $model = ChannelRate::class;

    public function definition(): array
    {
        return [
            'channel_provider_id' => ChannelProviderModel::factory(),
            'rate_plan_id' => RatePlan::factory(),
            'room_type_id' => RoomType::factory(),
            'channel_rate_code' => fake()->bothify('??-####'),
            'external_rate_id' => fake()->numerify('########'),
            'is_synced' => false,
            'last_synced_at' => null,
        ];
    }
}
