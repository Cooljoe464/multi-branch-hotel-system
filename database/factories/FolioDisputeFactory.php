<?php

namespace Database\Factories;

use App\Models\Folio;
use App\Models\FolioDispute;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FolioDispute>
 */
class FolioDisputeFactory extends Factory
{
    protected $model = FolioDispute::class;

    public function definition(): array
    {
        return [
            'folio_id' => Folio::factory(),
            'transaction_id' => null,
            'disputed_by' => User::factory(),
            'status' => 'open',
            'reason' => fake()->sentence(),
            'resolution_notes' => null,
            'resolved_by' => null,
            'resolved_at' => null,
            'amount_disputed' => fake()->numberBetween(1000, 50000),
            'metadata' => null,
        ];
    }

    public function open(): static
    {
        return $this->state(fn () => ['status' => 'open']);
    }

    public function underReview(): static
    {
        return $this->state(fn () => ['status' => 'under_review']);
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'status' => 'resolved',
            'resolved_by' => User::factory(),
            'resolved_at' => now(),
            'resolution_notes' => fake()->sentence(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => 'rejected',
            'resolved_by' => User::factory(),
            'resolved_at' => now(),
            'resolution_notes' => fake()->sentence(),
        ]);
    }

    public function forFolio(Folio $folio): static
    {
        return $this->state(fn () => ['folio_id' => $folio->id]);
    }

    public function forTransaction(Transaction $transaction): static
    {
        return $this->state(fn () => [
            'transaction_id' => $transaction->id,
            'amount_disputed' => $transaction->amount,
        ]);
    }
}
