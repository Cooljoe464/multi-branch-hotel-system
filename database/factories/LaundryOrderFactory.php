<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\LaundryOrder;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LaundryOrder>
 */
class LaundryOrderFactory extends Factory
{
    protected $model = LaundryOrder::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'reservation_id' => Reservation::factory(),
            'tablet_order_id' => null,
            'items' => [
                ['type' => 'shirt', 'count' => 3, 'notes' => ''],
                ['type' => 'trousers', 'count' => 1, 'notes' => ''],
            ],
            'status' => 'pending',
            'pickup_window' => null,
            'delivered_at' => null,
            'attendant_id' => null,
            'notes' => null,
            'metadata' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => 'pending']);
    }

    public function pickedUp(): static
    {
        return $this->state(fn () => ['status' => 'picked_up']);
    }

    public function processing(): static
    {
        return $this->state(fn () => ['status' => 'processing']);
    }

    public function delivered(): static
    {
        return $this->state(fn () => ['status' => 'delivered', 'delivered_at' => now()]);
    }

    public function forBranch(int $branchId): static
    {
        return $this->state(fn () => ['branch_id' => $branchId]);
    }
}
