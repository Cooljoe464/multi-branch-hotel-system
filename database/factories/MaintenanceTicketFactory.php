<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\MaintenanceTicket;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenanceTicket>
 */
class MaintenanceTicketFactory extends Factory
{
    protected $model = MaintenanceTicket::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'room_id' => null,
            'reported_by' => User::factory(),
            'assigned_to' => null,
            'ticket_number' => MaintenanceTicket::generateTicketNumber(),
            'category' => fake()->randomElement(['plumbing', 'electrical', 'hvac', 'furniture', 'appliance', 'structural', 'other']),
            'priority' => fake()->randomElement(['low', 'normal', 'high', 'urgent']),
            'status' => 'open',
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'resolution_notes' => null,
            'is_room_locked' => false,
            'estimated_cost' => fake()->optional(0.5)->numberBetween(50, 500),
            'actual_cost' => null,
            'metadata' => null,
        ];
    }

    public function open(): static
    {
        return $this->state(fn () => ['status' => 'open']);
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
            'started_at' => now()->subHours(2),
            'completed_at' => now(),
            'actual_cost' => fake()->optional(0.7)->numberBetween(50, 500),
        ]);
    }

    public function locked(): static
    {
        return $this->state(fn () => ['is_room_locked' => true]);
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

    public function reportedBy(User $user): static
    {
        return $this->state(fn () => ['reported_by' => $user->id]);
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
