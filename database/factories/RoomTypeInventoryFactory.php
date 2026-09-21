<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\RoomType;
use App\Models\RoomTypeInventory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoomTypeInventory>
 */
class RoomTypeInventoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'room_type_id' => RoomType::factory(),
            'stay_date' => fake()->date(),
            'total_rooms' => 10,
            'sold' => 0,
            'blocked' => 0,
            'overbooking_limit' => 0,
        ];
    }
}
