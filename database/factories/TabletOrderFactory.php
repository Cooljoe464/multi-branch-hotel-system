<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Reservation;
use App\Models\TabletOrder;
use App\Models\TabletSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TabletOrder>
 */
class TabletOrderFactory extends Factory
{
    protected $model = TabletOrder::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'tablet_session_id' => TabletSession::factory(),
            'reservation_id' => Reservation::factory(),
            'items' => [
                ['name' => 'Grilled Chicken', 'quantity' => 1, 'unit_price' => 4500, 'total' => 4500],
            ],
            'subtotal' => 4500,
            'tax_amount' => 450,
            'total' => 4950,
            'status' => 'pending',
            'payment_method' => 'room_charge',
            'payment_status' => 'pending',
            'payment_reference' => null,
            'dietary_requests' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => 'pending', 'payment_status' => 'pending']);
    }

    public function paid(): static
    {
        return $this->state(fn () => ['payment_status' => 'paid']);
    }

    public function preparing(): static
    {
        return $this->state(fn () => ['status' => 'preparing']);
    }

    public function delivered(): static
    {
        return $this->state(fn () => ['status' => 'delivered']);
    }

    public function forBranch(int $branchId): static
    {
        return $this->state(fn () => ['branch_id' => $branchId]);
    }
}
