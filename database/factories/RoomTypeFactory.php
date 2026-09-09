<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoomType>
 */
class RoomTypeFactory extends Factory
{
    protected $model = RoomType::class;

    public function definition(): array
    {
        $types = [
            ['name' => 'Standard Room', 'code' => 'STD', 'base_rate' => 120, 'max_occupancy' => 2, 'bed_count' => 1, 'bed_type' => 'queen'],
            ['name' => 'Deluxe Room', 'code' => 'DLX', 'base_rate' => 180, 'max_occupancy' => 2, 'bed_count' => 1, 'bed_type' => 'king'],
            ['name' => 'Junior Suite', 'code' => 'JRS', 'base_rate' => 250, 'max_occupancy' => 3, 'bed_count' => 1, 'bed_type' => 'king'],
            ['name' => 'Executive Suite', 'code' => 'EXS', 'base_rate' => 350, 'max_occupancy' => 4, 'bed_count' => 2, 'bed_type' => 'king'],
            ['name' => 'Presidential Suite', 'code' => 'PRE', 'base_rate' => 500, 'max_occupancy' => 4, 'bed_count' => 2, 'bed_type' => 'king'],
        ];

        $type = $types[array_rand($types)];

        return [
            'branch_id' => Branch::factory(),
            'name' => $type['name'],
            'code' => $type['code'].'-'.fake()->unique()->numberBetween(1, 9999),
            'description' => fake()->sentence(),
            'base_rate' => $type['base_rate'],
            'max_occupancy' => $type['max_occupancy'],
            'bed_count' => $type['bed_count'],
            'bed_type' => $type['bed_type'],
            'is_active' => true,
            'amenities' => ['wifi', 'tv', 'minibar'],
            'metadata' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['is_active' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
