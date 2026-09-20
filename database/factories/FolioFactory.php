<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Folio;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Folio>
 */
class FolioFactory extends Factory
{
    protected $model = Folio::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'reservation_id' => null,
            'parent_folio_id' => null,
            'folio_number' => Folio::generateFolioNumber(),
            'type' => 'individual',
            'status' => 'open',
            'description' => fake()->sentence(),
            'guest_name' => null,
            'notes' => null,
            'balance' => 0,
            'is_settled' => false,
            'closed_at' => null,
            'metadata' => null,
        ];
    }

    public function open(): static
    {
        return $this->state(fn () => ['status' => 'open']);
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'status' => 'closed',
            'is_settled' => true,
            'closed_at' => now(),
        ]);
    }

    public function master(): static
    {
        return $this->state(fn () => [
            'type' => 'master',
            'description' => fake()->company().' Corporate Account',
        ]);
    }

    public function child(): static
    {
        return $this->state(fn () => [
            'type' => 'child',
        ]);
    }

    public function individual(): static
    {
        return $this->state(fn () => ['type' => 'individual']);
    }

    public function staff(): static
    {
        return $this->state(fn () => [
            'type' => 'staff',
            'guest_name' => fake()->name(),
            'description' => fake()->randomElement(['House Account', 'Management Expense', 'Staff Purchase', 'Internal Recharge']),
        ]);
    }

    public function nonGuest(): static
    {
        return $this->state(fn () => [
            'type' => 'non_guest',
            'guest_name' => fake()->company(),
            'description' => fake()->randomElement(['Corporate Account', 'Event Hosting', 'Internal Recharge', 'Vendor Account']),
        ]);
    }

    public function forBranch(int $branchId): static
    {
        return $this->state(fn () => ['branch_id' => $branchId]);
    }

    public function withReservation(Reservation $reservation): static
    {
        return $this->state(fn () => [
            'reservation_id' => $reservation->id,
            'branch_id' => $reservation->branch_id,
        ]);
    }

    public function withParent(Folio $parent): static
    {
        return $this->state(fn () => [
            'parent_folio_id' => $parent->id,
            'branch_id' => $parent->branch_id,
        ]);
    }

    public function withBalance(int $balance): static
    {
        return $this->state(fn () => ['balance' => $balance]);
    }

    public function withGuestName(string $name): static
    {
        return $this->state(fn () => ['guest_name' => $name]);
    }
}
