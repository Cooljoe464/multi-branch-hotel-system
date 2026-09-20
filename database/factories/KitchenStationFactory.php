<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\KitchenStation;
use App\Models\Outlet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KitchenStation>
 */
class KitchenStationFactory extends Factory
{
    protected $model = KitchenStation::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'outlet_id' => Outlet::factory(),
            'name' => fake()->words(2, true),
            'code' => strtoupper(fake()->bothify('??-####')),
            'is_active' => true,
            'metadata' => null,
        ];
    }

    public function forBranch(int $branchId): static
    {
        return $this->state(fn () => ['branch_id' => $branchId]);
    }
}
