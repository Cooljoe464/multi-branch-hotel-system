<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Folio;
use App\Models\PaymentTransaction;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentTransaction>
 */
class PaymentTransactionFactory extends Factory
{
    protected $model = PaymentTransaction::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'folio_id' => Folio::factory(),
            'reservation_id' => null,
            'paystack_reference' => 'HMS-'.strtoupper(fake()->unique()->bothify('????????????')),
            'paystack_access_code' => null,
            'type' => 'charge',
            'status' => 'pending',
            'amount' => fake()->numberBetween(1000, 200000),
            'currency' => 'NGN',
            'customer_email' => fake()->safeEmail(),
            'authorization_code' => null,
            'metadata' => null,
            'paid_at' => null,
            'webhook_payload' => null,
        ];
    }

    public function success(): static
    {
        return $this->state(fn () => [
            'status' => 'success',
            'paid_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => ['status' => 'failed']);
    }

    public function preauth(): static
    {
        return $this->state(fn () => [
            'type' => 'authorization',
            'authorization_code' => 'AUTH_'.strtoupper(fake()->bothify('??????????')),
        ]);
    }

    public function forBranch(int $branchId): static
    {
        return $this->state(fn () => ['branch_id' => $branchId]);
    }

    public function forFolio(Folio $folio): static
    {
        return $this->state(fn () => [
            'folio_id' => $folio->id,
            'branch_id' => $folio->branch_id,
        ]);
    }

    public function withReservation(Reservation $reservation): static
    {
        return $this->state(fn () => ['reservation_id' => $reservation->id]);
    }
}
