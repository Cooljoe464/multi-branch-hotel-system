<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\OperationalChecklist;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OperationalChecklist> */
class OperationalChecklistFactory extends Factory
{
    protected $model = OperationalChecklist::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'department' => fake()->randomElement(['front_desk', 'housekeeping', 'food_beverage', 'security', 'maintenance']),
            'shift_type' => fake()->randomElement(['morning', 'evening', 'night']),
            'title' => fake()->sentence(3),
            'items' => [
                ['id' => 1, 'task' => 'Check lobby cleanliness', 'done' => false, 'checked_at' => null, 'note' => null],
                ['id' => 2, 'task' => 'Verify cash drawer', 'done' => false, 'checked_at' => null, 'note' => null],
            ],
            'status' => 'pending',
            'completed_by' => null,
            'completed_at' => null,
            'verified_by' => null,
        ];
    }
}
