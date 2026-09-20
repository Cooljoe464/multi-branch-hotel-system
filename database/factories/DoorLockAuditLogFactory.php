<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\DoorLockAuditLog;
use App\Models\DoorLockGateway;
use App\Models\Reservation;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DoorLockAuditLog> */
class DoorLockAuditLogFactory extends Factory
{
    protected $model = DoorLockAuditLog::class;

    public function definition(): array
    {
        $actions = ['unlock', 'lock', 'encode_credential', 'revoke_credential', 'update_pin'];
        $statuses = ['success', 'failed', 'pending'];

        $validFrom = $this->faker->dateTimeBetween('-1 month', 'now');
        $validUntil = (clone $validFrom)->modify('+7 days');

        return [
            'branch_id' => Branch::factory(),
            'reservation_id' => Reservation::factory(),
            'room_id' => Room::factory(),
            'gateway_id' => DoorLockGateway::factory(),
            'action' => $this->faker->randomElement($actions),
            'credential_id' => strtoupper($this->faker->bothify('CRD-####-####')),
            'pin_code' => $this->faker->numerify('####'),
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
            'status' => $this->faker->randomElement($statuses),
            'payload' => [
                'device_id' => strtoupper($this->faker->bothify('DEV-########')),
                'firmware_version' => $this->faker->randomFloat(1, 1, 5),
            ],
        ];
    }

    public function success(): static
    {
        return $this->state(fn () => ['status' => 'success']);
    }

    public function failed(): static
    {
        return $this->state(fn () => ['status' => 'failed']);
    }

    public function forBranch(Branch $branch): static
    {
        return $this->state(fn () => ['branch_id' => $branch->id]);
    }

    public function forReservation(Reservation $reservation): static
    {
        return $this->state(fn () => ['reservation_id' => $reservation->id]);
    }

    public function forRoom(Room $room): static
    {
        return $this->state(fn () => ['room_id' => $room->id]);
    }

    public function forGateway(DoorLockGateway $gateway): static
    {
        return $this->state(fn () => ['gateway_id' => $gateway->id]);
    }

    public function unlock(): static
    {
        return $this->state(fn () => ['action' => 'unlock']);
    }

    public function lock(): static
    {
        return $this->state(fn () => ['action' => 'lock']);
    }
}
