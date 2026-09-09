<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reservation>
 */
class ReservationFactory extends Factory
{
    protected $model = Reservation::class;

    public function definition(): array
    {
        $checkIn = fake()->dateTimeBetween('-1 month', '+1 month');
        $checkOut = (clone $checkIn)->modify('+'.fake()->numberBetween(1, 7).' days');

        return [
            'branch_id' => Branch::factory(),
            'room_id' => null,
            'room_type_id' => RoomType::factory(),
            'confirmation_number' => Reservation::generateConfirmationNumber(),
            'status' => 'confirmed',
            'source' => fake()->randomElement(['direct', 'booking.com', 'expedia', 'phone', 'walk_in']),
            'guest_name' => fake()->name(),
            'guest_email' => fake()->safeEmail(),
            'guest_phone' => fake()->phoneNumber(),
            'guest_notes' => null,
            'adults' => fake()->numberBetween(1, 3),
            'children' => fake()->numberBetween(0, 2),
            'check_in_date' => $checkIn->format('Y-m-d'),
            'check_out_date' => $checkOut->format('Y-m-d'),
            'room_rate' => fake()->numberBetween(100, 500),
            'total_amount' => fake()->numberBetween(100, 3500),
            'amount_paid' => 0,
            'payment_status' => 'pending',
            'is_group_booking' => false,
            'group_id' => null,
            'special_requests' => null,
            'metadata' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => 'pending']);
    }

    public function confirmed(): static
    {
        return $this->state(fn () => ['status' => 'confirmed']);
    }

    public function reserved(): static
    {
        return $this->state(fn () => ['status' => 'reserved']);
    }

    public function checkedIn(): static
    {
        return $this->state(fn () => [
            'status' => 'checked_in',
            'actual_check_in_at' => now(),
        ]);
    }

    public function checkedOut(): static
    {
        return $this->state(fn () => [
            'status' => 'checked_out',
            'actual_check_in_at' => now()->subDay(),
            'actual_check_out_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => 'cancelled']);
    }

    public function groupBooking(string $groupId): static
    {
        return $this->state(fn () => [
            'is_group_booking' => true,
            'group_id' => $groupId,
        ]);
    }

    public function forBranch(int $branchId): static
    {
        return $this->state(fn () => ['branch_id' => $branchId]);
    }

    public function withRoom(Room $room): static
    {
        return $this->state(fn () => [
            'room_id' => $room->id,
            'room_type_id' => $room->room_type_id,
        ]);
    }
}
