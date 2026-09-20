<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\RoomType;
use App\Models\YieldRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<YieldRule> */
class YieldRuleFactory extends Factory
{
    protected $model = YieldRule::class;

    public function definition(): array
    {
        $minOccupancy = $this->faker->numberBetween(0, 70);
        $maxOccupancy = $minOccupancy + $this->faker->numberBetween(10, 30);

        return [
            'branch_id' => Branch::factory(),
            'room_type_id' => RoomType::factory(),
            'min_occupancy_pct' => $minOccupancy,
            'max_occupancy_pct' => min($maxOccupancy, 100),
            'rate_multiplier' => $this->faker->randomFloat(2, 0.8, 1.5),
            'mlos_override' => $this->faker->optional(0.3)->numberBetween(1, 5),
            'cta_override' => $this->faker->optional(0.2)->boolean(),
            'is_active' => true,
            'priority' => $this->faker->numberBetween(1, 100),
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

    public function forBranch(Branch $branch): static
    {
        return $this->state(fn () => ['branch_id' => $branch->id]);
    }

    public function forRoomType(RoomType $roomType): static
    {
        return $this->state(fn () => ['room_type_id' => $roomType->id]);
    }

    public function withMultiplier(float $multiplier): static
    {
        return $this->state(fn () => ['rate_multiplier' => $multiplier]);
    }

    public function withPriority(int $priority): static
    {
        return $this->state(fn () => ['priority' => $priority]);
    }
}
