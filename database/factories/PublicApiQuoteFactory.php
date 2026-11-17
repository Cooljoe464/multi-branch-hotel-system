<?php

namespace Database\Factories;

use App\Models\ApiKey;
use App\Models\Branch;
use App\Models\PublicApiQuote;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PublicApiQuote>
 */
class PublicApiQuoteFactory extends Factory
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
            'room_type_id' => RoomType::factory(),
            'rate_plan_id' => RatePlan::factory(),
            'quote_hash' => hash('sha256', fake()->unique()->uuid()),
            'check_in' => now()->addDays(10)->toDateString(),
            'check_out' => now()->addDays(11)->toDateString(),
            'currency_code' => 'NGN',
            'subtotal_minor' => 10000,
            'tax_total_minor' => 0,
            'total_minor' => 10000,
            'rate_snapshot' => [],
            'tax_snapshot' => [],
            'expires_at' => now()->addMinutes(15),
            'consumed_at' => null,
        ];
    }

    public function forBooking(ApiKey $apiKey, RoomType $roomType, RatePlan $ratePlan): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $apiKey->tenant_id,
            'branch_id' => $apiKey->branch_id,
            'api_key_id' => $apiKey->id,
            'room_type_id' => $roomType->id,
            'rate_plan_id' => $ratePlan->id,
        ]);
    }
}
