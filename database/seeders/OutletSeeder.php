<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\KitchenStation;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Seeder;

class OutletSeeder extends Seeder
{
    public function run(): void
    {
        $branches = Branch::all();
        $cashier = User::where('email', 'cashier@hotel.com')->first();

        foreach ($branches as $branch) {
            $restaurant = Outlet::create([
                'branch_id' => $branch->id,
                'name' => 'Main Restaurant',
                'code' => 'REST-01',
                'type' => 'restaurant',
                'is_active' => true,
            ]);

            KitchenStation::create([
                'branch_id' => $branch->id,
                'outlet_id' => $restaurant->id,
                'name' => 'Grill Station',
                'code' => 'GRILL',
                'is_active' => true,
            ]);

            KitchenStation::create([
                'branch_id' => $branch->id,
                'outlet_id' => $restaurant->id,
                'name' => 'Prep Station',
                'code' => 'PREP',
                'is_active' => true,
            ]);

            $bar = Outlet::create([
                'branch_id' => $branch->id,
                'name' => 'Pool Bar',
                'code' => 'BAR-01',
                'type' => 'bar',
                'is_active' => true,
            ]);

            KitchenStation::create([
                'branch_id' => $branch->id,
                'outlet_id' => $bar->id,
                'name' => 'Bar Station',
                'code' => 'BAR',
                'is_active' => true,
            ]);

            Outlet::create([
                'branch_id' => $branch->id,
                'name' => 'Spa & Wellness',
                'code' => 'SPA-01',
                'type' => 'spa',
                'is_active' => true,
            ]);

            Outlet::create([
                'branch_id' => $branch->id,
                'name' => 'Laundry Service',
                'code' => 'LAUN-01',
                'type' => 'laundry',
                'is_active' => true,
            ]);

            // Assign restaurant and bar outlets to the cashier
            if ($cashier) {
                $cashier->posOutlets()->attach([$restaurant->id, $bar->id]);
            }
        }
    }
}
