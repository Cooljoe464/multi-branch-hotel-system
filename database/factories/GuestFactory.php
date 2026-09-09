<?php

namespace Database\Factories;

use App\Models\Guest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Guest>
 */
class GuestFactory extends Factory
{
    protected $model = Guest::class;

    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'date_of_birth' => fake()->dateTimeBetween('-60 years', '-18 years'),
            'nationality' => fake()->countryCode(),
            'id_type' => fake()->randomElement(['passport', 'drivers_license', 'national_id']),
            'id_number' => strtoupper(fake()->bothify('??#####')),
            'company' => fake()->optional(0.3)->company(),
            'job_title' => fake()->optional(0.3)->jobTitle(),
            'vip_status' => 'none',
            'total_stays' => 0,
            'total_nights' => 0,
            'total_spent' => 0,
            'currency_code' => 'USD',
            'preferred_language' => 'en',
            'preferred_currency' => 'USD',
            'dietary_restrictions' => fake()->optional(0.2)->randomElement(['None', 'Vegetarian', 'Gluten-free', 'Nut allergy']),
            'special_notes' => fake()->optional(0.2)->sentence(),
            'internal_notes' => null,
            'metadata' => null,
            'last_stayed_at' => null,
        ];
    }

    public function returning(): static
    {
        return $this->state(fn () => [
            'total_stays' => fake()->numberBetween(2, 20),
            'total_nights' => fake()->numberBetween(5, 100),
            'total_spent' => fake()->numberBetween(50000, 2000000),
            'last_stayed_at' => fake()->dateTimeBetween('-1 year', 'now'),
        ]);
    }

    public function vip(string $status = 'gold'): static
    {
        return $this->state(fn () => [
            'vip_status' => $status,
            'total_stays' => match ($status) {
                'silver' => fake()->numberBetween(3, 9),
                'gold' => fake()->numberBetween(10, 24),
                'platinum' => fake()->numberBetween(25, 49),
                'diamond' => fake()->numberBetween(50, 200),
                default => 0,
            },
            'total_spent' => match ($status) {
                'silver' => fake()->numberBetween(100000, 499999),
                'gold' => fake()->numberBetween(500000, 1999999),
                'platinum' => fake()->numberBetween(2000000, 4999999),
                'diamond' => fake()->numberBetween(5000000, 20000000),
                default => 0,
            },
        ]);
    }
}
