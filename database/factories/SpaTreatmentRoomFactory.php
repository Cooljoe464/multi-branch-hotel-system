<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\SpaTreatmentRoom;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SpaTreatmentRoom> */
class SpaTreatmentRoomFactory extends Factory
{
    protected $model = SpaTreatmentRoom::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'name' => 'Room '.fake()->numberBetween(1, 20),
            'room_type' => fake()->randomElement(['single', 'couple', 'wet_room', 'sauna']),
            'is_active' => true,
        ];
    }
}
