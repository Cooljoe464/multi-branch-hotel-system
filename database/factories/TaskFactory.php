<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Room;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'room_id' => null,
            'assigned_to' => null,
            'type' => fake()->randomElement(['cleaning', 'deep_clean', 'turnover', 'inspection', 'laundry', 'maintenance_request']),
            'priority' => fake()->randomElement(['low', 'normal', 'high', 'urgent']),
            'status' => 'pending',
            'description' => fake()->sentence(),
            'notes' => null,
            'estimated_minutes' => fake()->numberBetween(15, 120),
            'actual_minutes' => null,
            'metadata' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => 'pending']);
    }

    public function inProgress(): static
    {
        return $this->state(fn () => [
            'status' => 'in_progress',
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => 'completed',
            'started_at' => now()->subMinutes(30),
            'completed_at' => now(),
            'actual_minutes' => fake()->numberBetween(15, 120),
        ]);
    }

    public function cleaning(): static
    {
        return $this->state(fn () => ['type' => 'cleaning']);
    }

    public function deepClean(): static
    {
        return $this->state(fn () => ['type' => 'deep_clean']);
    }

    public function turnover(): static
    {
        return $this->state(fn () => ['type' => 'turnover']);
    }

    public function urgent(): static
    {
        return $this->state(fn () => ['priority' => 'urgent']);
    }

    public function forRoom(Room $room): static
    {
        return $this->state(fn () => [
            'room_id' => $room->id,
            'branch_id' => $room->branch_id,
        ]);
    }

    public function assignedTo(User $user): static
    {
        return $this->state(fn () => ['assigned_to' => $user->id]);
    }

    public function forBranch(int $branchId): static
    {
        return $this->state(fn () => ['branch_id' => $branchId]);
    }
}
