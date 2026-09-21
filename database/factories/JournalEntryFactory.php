<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JournalEntry>
 */
class JournalEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'business_date' => fake()->date(),
            'event' => 'charge.posted',
            'debit_account' => 'GUEST_LEDGER',
            'credit_account' => 'REVENUE',
            'amount_minor' => fake()->numberBetween(1000, 50000),
            'currency_code' => 'NGN',
            'fx_rate_to_branch_minor' => 1000000,
            'source_type' => null,
            'source_id' => null,
            'idempotency_scope' => null,
            'idempotency_key' => null,
            'created_by' => null,
            'posted_at' => now(),
        ];
    }
}
