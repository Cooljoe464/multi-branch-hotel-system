<?php

namespace Database\Factories;

use App\Models\CityLedgerAccount;
use App\Models\CityLedgerTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CityLedgerTransaction>
 */
class CityLedgerTransactionFactory extends Factory
{
    protected $model = CityLedgerTransaction::class;

    public function definition(): array
    {
        return [
            'city_ledger_account_id' => CityLedgerAccount::factory(),
            'folio_id' => null,
            'type' => fake()->randomElement(['debit', 'credit', 'payment']),
            'amount' => fake()->numberBetween(1000, 100000),
            'reference' => fake()->bothify('INV-####'),
            'notes' => null,
        ];
    }

    public function debit(): static
    {
        return $this->state(fn () => ['type' => 'debit']);
    }

    public function payment(): static
    {
        return $this->state(fn () => ['type' => 'payment']);
    }
}
