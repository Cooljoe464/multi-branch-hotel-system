<?php

namespace Database\Factories;

use App\Models\ChannelProviderModel;
use App\Models\ChannelReservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChannelReservation>
 */
class ChannelReservationFactory extends Factory
{
    protected $model = ChannelReservation::class;

    public function definition(): array
    {
        return [
            'channel_provider_id' => ChannelProviderModel::factory(),
            'reservation_id' => null,
            'channel_booking_id' => fake()->uuid(),
            'raw_payload' => ['test' => true],
            'sync_status' => 'pending',
        ];
    }
}
