<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\OverbookingAllowance;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OverbookingAllowance>
 */
class OverbookingAllowanceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $branch = Branch::factory()->create();
        $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);

        return [
            'tenant_id' => $branch->tenant_id,
            'branch_id' => $branch->id,
            'room_type_id' => $roomType->id,
            'starts_on' => now()->addDays(2)->toDateString(),
            'ends_on' => now()->addDays(5)->toDateString(),
            'allowed_count' => 1,
            'allowed_sources' => ['front_desk'],
            'reason' => fake()->sentence(),
            'created_by' => User::factory()->create(['branch_id' => $branch->id])->id,
            'revoked_at' => null,
        ];
    }

    public function forRoomType(Branch $branch, RoomType $roomType): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $branch->tenant_id,
            'branch_id' => $branch->id,
            'room_type_id' => $roomType->id,
            'created_by' => User::factory()->create(['branch_id' => $branch->id])->id,
        ]);
    }
}
