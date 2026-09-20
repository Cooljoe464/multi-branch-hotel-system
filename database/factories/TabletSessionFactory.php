<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\TabletSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TabletSession>
 */
class TabletSessionFactory extends Factory
{
    protected $model = TabletSession::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'room_id' => Room::factory(),
            'reservation_id' => Reservation::factory(),
            'confirmation_number' => 'HMS-'.strtoupper(fake()->bothify('????????')),
            'device_id' => fake()->uuid(),
            'last_active_at' => now(),
            'wiped_at' => null,
            'metadata' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['wiped_at' => null, 'last_active_at' => now()]);
    }

    public function wiped(): static
    {
        return $this->state(fn () => ['wiped_at' => now()]);
    }

    public function forBranch(int $branchId): static
    {
        return $this->state(fn () => ['branch_id' => $branchId]);
    }
}
