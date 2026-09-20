<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    protected static int $counter = 0;

    public function definition(): array
    {
        static::$counter++;

        $name = fake()->company();

        return [
            'name' => $name,
            'code' => 'BR-'.str_pad((string) static::$counter, 3, '0', STR_PAD_LEFT),
            'slug' => Str::slug($name).'-'.static::$counter,
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => fake()->city(),
            'country' => 'NG',
            'postal_code' => fake()->postcode(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->unique()->companyEmail(),
            'timezone' => 'Africa/Lagos',
            'currency_code' => 'NGN',
            'currency_symbol' => '₦',
            'tax_rate' => fake()->randomFloat(2, 0, 15),
            'tax_label' => 'Tax',
            'is_active' => true,
            'is_primary' => false,
            'settings' => null,
            'metadata' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['is_active' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function primary(): static
    {
        return $this->state(fn () => ['is_primary' => true]);
    }
}
