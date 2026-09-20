<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Folio;
use App\Models\MenuItem;
use App\Models\PosCharge;
use App\Models\Reservation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class PosChargeSeeder extends Seeder
{
    public function run(): void
    {
        $branches = Branch::all();

        foreach ($branches as $branch) {
            $reservations = Reservation::where('branch_id', $branch->id)
                ->whereIn('status', ['checked_in', 'checked_out'])
                ->get();

            if ($reservations->isEmpty()) {
                continue;
            }

            $this->createPosCharges($branch, $reservations);
        }
    }

    /** @param  Collection<int, Reservation>  $reservations */
    private function createPosCharges(Branch $branch, Collection $reservations): void
    {
        $outlets = ['restaurant', 'bar', 'pool_bar', 'room_service'];

        $menuItemNames = [
            'restaurant' => [
                ['name' => 'Grilled Chicken', 'price' => 4500],
                ['name' => 'Caesar Salad', 'price' => 3000],
                ['name' => 'Pasta Carbonara', 'price' => 5000],
                ['name' => 'Beef Steak', 'price' => 8000],
                ['name' => 'Fish & Chips', 'price' => 4000],
            ],
            'bar' => [
                ['name' => 'Fresh Orange Juice', 'price' => 1500],
                ['name' => 'Espresso', 'price' => 800],
                ['name' => 'Sparkling Water', 'price' => 500],
                ['name' => 'House Wine', 'price' => 3500],
                ['name' => 'Cocktail', 'price' => 4000],
            ],
            'pool_bar' => [
                ['name' => 'Fresh Orange Juice', 'price' => 1500],
                ['name' => 'Sparkling Water', 'price' => 500],
                ['name' => 'Cocktail', 'price' => 4000],
                ['name' => 'House Wine', 'price' => 3500],
            ],
            'room_service' => [
                ['name' => 'Grilled Chicken', 'price' => 4500],
                ['name' => 'Caesar Salad', 'price' => 3000],
                ['name' => 'House Wine', 'price' => 3500],
                ['name' => 'Espresso', 'price' => 800],
            ],
        ];

        for ($i = 0; $i < 12; $i++) {
            $reservation = $reservations[$i % $reservations->count()] ?? null;

            if (! $reservation) {
                continue;
            }

            $outlet = $outlets[$i % count($outlets)];
            $folio = Folio::where('reservation_id', $reservation->id)->first();

            $itemDef = $menuItemNames[$outlet][$i % count($menuItemNames[$outlet])];
            $quantity = rand(1, 3);

            $menuItem = MenuItem::where('branch_id', $branch->id)
                ->where('name', $itemDef['name'])
                ->first();

            $subtotal = $itemDef['price'] * $quantity;
            $taxAmount = (int) ($subtotal * 0.075);
            $total = $subtotal + $taxAmount;

            $status = $i % 3 === 0 ? 'pending' : 'posted';

            PosCharge::firstOrCreate(
                [
                    'branch_id' => $branch->id,
                    'reservation_id' => $reservation->id,
                    'outlet' => $outlet,
                ],
                [
                    'folio_id' => $folio?->id,
                    'items' => [
                        [
                            'menu_item_id' => $menuItem?->id,
                            'name' => $itemDef['name'],
                            'quantity' => $quantity,
                            'price' => $itemDef['price'],
                            'total' => $subtotal,
                        ],
                    ],
                    'subtotal' => $subtotal,
                    'tax_amount' => $taxAmount,
                    'total' => $total,
                    'status' => $status,
                    'posted_at' => $status === 'posted' ? now()->subHours(rand(1, 48)) : null,
                ]
            );
        }
    }
}
