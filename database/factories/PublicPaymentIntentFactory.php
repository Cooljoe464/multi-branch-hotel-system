<?php

namespace Database\Factories;

use App\Models\ApiKey;
use App\Models\Branch;
use App\Models\Folio;
use App\Models\InventoryHold;
use App\Models\PaymentConnection;
use App\Models\PublicApiQuote;
use App\Models\PublicPaymentIntent;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PublicPaymentIntent>
 */
class PublicPaymentIntentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'branch_id' => Branch::factory(),
            'api_key_id' => ApiKey::factory(),
            'hold_id' => InventoryHold::factory(),
            'quote_id' => PublicApiQuote::factory(),
            'payment_connection_id' => PaymentConnection::factory(),
            'folio_id' => Folio::factory(),
            'payment_transaction_id' => null,
            'reservation_id' => null,
            'guest_name' => fake()->name(),
            'guest_email' => fake()->safeEmail(),
            'guest_phone' => null,
            'adults' => 1,
            'children' => 0,
            'status' => 'initializing',
            'gateway_reference' => null,
            'checkout_url' => null,
            'access_token_hash' => hash('sha256', fake()->unique()->uuid()),
            'amount_minor' => 10000,
            'currency_code' => 'NGN',
            'expires_at' => now()->addMinutes(10),
        ];
    }
}
