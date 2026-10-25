<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\BranchSecret;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BranchSecret>
 */
class BranchSecretFactory extends Factory
{
    protected $model = BranchSecret::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'key' => 'vendor.'.fake()->unique()->slug(2),
            'value' => 'enc:'.fake()->sha256(),
            'rotated_at' => null,
        ];
    }
}
