<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    protected $model = Room::class;

    public function definition(): array
    {
        $floor = fake()->numberBetween(1, 10);
        $roomNum = fake()->unique()->numberBetween(1, 99);
        $roomNumber = sprintf('%d%02d', $floor, $roomNum);

        return [
            'branch_id' => Branch::factory(),
            'room_type_id' => RoomType::factory(),
            'number' => $roomNumber,
            'floor' => (string) $floor,
            'wing' => fake()->randomElement(['north', 'south', 'east', 'west']),
            'status' => 'available',
            'is_accessible' => fake()->boolean(10),
            'is_smoking' => fake()->boolean(5),
            'is_active' => true,
            'notes' => null,
            'metadata' => null,
        ];
    }

    public function available(): static
    {
        return $this->state(fn () => ['status' => 'available']);
    }

    public function occupied(): static
    {
        return $this->state(fn () => ['status' => 'occupied']);
    }

    public function dirty(): static
    {
        return $this->state(fn () => ['status' => 'dirty']);
    }

    public function outOfOrder(): static
    {
        return $this->state(fn () => ['status' => 'out_of_order']);
    }

    public function forBranch(int $branchId): static
    {
        return $this->state(fn () => ['branch_id' => $branchId]);
    }

    public function forFloor(string $floor): static
    {
        return $this->state(fn () => ['floor' => $floor]);
    }
}
