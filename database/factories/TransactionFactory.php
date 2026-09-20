<?php

namespace Database\Factories;

use App\Models\Folio;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        return [
            'folio_id' => Folio::factory(),
            'type' => 'debit',
            'category' => fake()->randomElement(['room_rate', 'restaurant', 'minibar', 'laundry', 'spa', 'parking', 'misc']),
            'description' => fake()->sentence(),
            'amount' => fake()->numberBetween(500, 50000),
            'reference_type' => null,
            'reference_id' => null,
            'posted_by' => null,
            'is_taxable' => true,
            'tax_amount' => 0,
            'is_voided' => false,
            'voided_at' => null,
            'metadata' => null,
        ];
    }

    public function debit(): static
    {
        return $this->state(fn () => ['type' => 'debit']);
    }

    public function credit(): static
    {
        return $this->state(fn () => [
            'type' => 'credit',
            'is_taxable' => false,
        ]);
    }

    public function voided(): static
    {
        return $this->state(fn () => [
            'is_voided' => true,
            'voided_at' => now(),
        ]);
    }

    public function roomRate(): static
    {
        return $this->state(fn () => [
            'category' => 'room_rate',
            'description' => 'Room charge - Night '.fake()->numberBetween(1, 7),
        ]);
    }

    public function payment(): static
    {
        return $this->state(fn () => [
            'type' => 'credit',
            'category' => 'payment',
            'is_taxable' => false,
        ]);
    }

    public function postedBy(User $user): static
    {
        return $this->state(fn () => ['posted_by' => $user->id]);
    }

    public function forFolio(Folio $folio): static
    {
        return $this->state(fn () => ['folio_id' => $folio->id]);
    }
}
