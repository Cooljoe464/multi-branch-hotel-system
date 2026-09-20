<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Outlet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Outlet>
 */
class OutletFactory extends Factory
{
    protected $model = Outlet::class;

    public function definition(): array
    {
        $typeOptions = ['restaurant', 'bar', 'spa', 'gift_shop', 'laundry'];
        $type = $typeOptions[array_rand($typeOptions)];

        $words = fake()->words(2, true);
        $name = (is_string($words) ? $words : 'Hotel Outlet').' '.ucfirst($type);

        return [
            'branch_id' => Branch::factory(),
            'name' => $name,
            'code' => strtoupper(fake()->bothify('??-####')),
            'type' => $type,
            'is_active' => true,
            'settings' => null,
            'metadata' => null,
        ];
    }

    public function restaurant(): static
    {
        return $this->state(fn () => ['type' => 'restaurant']);
    }

    public function bar(): static
    {
        return $this->state(fn () => ['type' => 'bar']);
    }

    public function spa(): static
    {
        return $this->state(fn () => ['type' => 'spa']);
    }

    public function forBranch(int $branchId): static
    {
        return $this->state(fn () => ['branch_id' => $branchId]);
    }
}
