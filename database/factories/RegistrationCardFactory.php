<?php

namespace Database\Factories;

use App\Models\RegistrationCard;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegistrationCard>
 */
class RegistrationCardFactory extends Factory
{
    protected $model = RegistrationCard::class;

    public function definition(): array
    {
        return [
            'reservation_id' => Reservation::factory(),
            'guest_name' => fake()->name(),
            'id_type' => fake()->randomElement(['passport', 'drivers_license', 'national_id']),
            'id_number' => fake()->bothify('??-######'),
            'id_image_url' => null,
            'signature_image_url' => null,
            'signed_at' => null,
            'metadata' => null,
        ];
    }

    public function signed(): static
    {
        return $this->state(fn () => [
            'signed_at' => now(),
            'signature_image_url' => 'data:image/png;base64,fakeSignatureData',
        ]);
    }
}
