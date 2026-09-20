<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\RateOverride;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RateOverride> */
class RateOverrideFactory extends Factory
{
    protected $model = RateOverride::class;

    public function definition(): array
    {
        $startDate = $this->faker->dateTimeBetween('-1 month', '+1 month');
        $endDate = (clone $startDate)->modify('+'.$this->faker->numberBetween(1, 14).' days');

        return [
            'branch_id' => Branch::factory(),
            'room_type_id' => RoomType::factory(),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'rate_override' => $this->faker->numberBetween(100, 500),
            'mlos' => $this->faker->optional(0.3)->numberBetween(1, 5),
            'cta' => $this->faker->boolean(20),
            'ctd' => $this->faker->boolean(20),
            'is_active' => true,
            'notes' => $this->faker->optional()->sentence(),
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

    public function withCta(): static
    {
        return $this->state(fn () => ['cta' => true]);
    }

    public function withCtd(): static
    {
        return $this->state(fn () => ['ctd' => true]);
    }

    public function withMlos(int $mlos): static
    {
        return $this->state(fn () => ['mlos' => $mlos]);
    }
}
