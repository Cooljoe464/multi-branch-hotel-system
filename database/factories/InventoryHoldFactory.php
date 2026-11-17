<?php

namespace Database\Factories;

use App\Models\ApiKey;
use App\Models\InventoryHold;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryHold>
 */
class InventoryHoldFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = ApiKey::factory()->create();
        $roomType = RoomType::factory()->create(['branch_id' => $key->branch_id]);

        return [
            'tenant_id' => $key->tenant_id,
            'branch_id' => $key->branch_id,
            'room_type_id' => $roomType->id,
            'api_key_id' => $key->id,
            'source' => 'public_api',
            'check_in' => now()->addDay()->toDateString(),
            'check_out' => now()->addDays(2)->toDateString(),
            'status' => 'active',
            'session_id' => fake()->uuid(),
            'client_ip' => fake()->ipv4(),
            'expires_at' => now()->addMinutes(15),
        ];
    }
}
