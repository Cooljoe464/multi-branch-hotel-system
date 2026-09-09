<?php

namespace Database\Factories;

use App\Models\Guest;
use App\Models\GuestPreference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GuestPreference>
 */
class GuestPreferenceFactory extends Factory
{
    protected $model = GuestPreference::class;

    public function definition(): array
    {
        $categories = [
            'room' => ['floor' => ['high', 'low', 'mid'], 'view' => ['city', 'pool', 'garden', 'ocean'], 'bed' => ['soft', 'firm', 'extra_pillow']],
            'pillow' => ['type' => ['down', 'foam', 'hypoallergenic'], 'count' => ['1', '2', '3']],
            'minibar' => ['stock' => ['full', 'empty', 'beer_only', 'no_alcohol']],
            'newspaper' => ['daily' => ['none', 'local', 'international', 'business']],
            'wake_up_call' => ['time' => ['06:00', '06:30', '07:00', '07:30', '08:00']],
            'temperature' => ['setting' => ['cool', 'moderate', 'warm'], 'units' => ['celsius', 'fahrenheit']],
        ];

        $category = fake()->randomElement(array_keys($categories));
        $options = $categories[$category];
        $key = fake()->randomElement(array_keys($options));
        $value = fake()->randomElement($options[$key]);

        return [
            'guest_id' => Guest::factory(),
            'category' => $category,
            'key' => $key,
            'value' => $value,
            'notes' => fake()->optional(0.3)->sentence(),
        ];
    }
}
