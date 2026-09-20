<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Folio;
use App\Models\PosCharge;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PosCharge>
 */
class PosChargeFactory extends Factory
{
    protected $model = PosCharge::class;

    public function definition(): array
    {
        $items = [
            ['name' => fake()->randomElement(['Grilled Chicken', 'Pasta Carbonara', 'Caesar Salad', 'Club Sandwich', 'Fish & Chips']), 'qty' => 1, 'unit_price' => fake()->numberBetween(800, 5000), 'total' => 0],
        ];
        $items[0]['total'] = $items[0]['qty'] * $items[0]['unit_price'];

        return [
            'branch_id' => Branch::factory(),
            'reservation_id' => Reservation::factory(),
            'folio_id' => Folio::factory(),
            'transaction_id' => null,
            'outlet' => fake()->randomElement(['restaurant', 'bar', 'pool_bar', 'room_service']),
            'items' => $items,
            'subtotal' => $items[0]['total'],
            'tax_amount' => 0,
            'total' => $items[0]['total'],
            'status' => 'pending',
            'posted_at' => null,
            'metadata' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => 'pending']);
    }

    public function posted(): static
    {
        return $this->state(fn () => [
            'status' => 'posted',
            'posted_at' => now(),
        ]);
    }

    public function forBranch(int $branchId): static
    {
        return $this->state(fn () => ['branch_id' => $branchId]);
    }

    public function forOutlet(string $outlet): static
    {
        return $this->state(fn () => ['outlet' => $outlet]);
    }
}
