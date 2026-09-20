<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\KitchenStation;
use App\Models\MenuItem;
use App\Models\MenuItemStation;
use Illuminate\Database\Seeder;

class MenuItemStationSeeder extends Seeder
{
    public function run(): void
    {
        $branches = Branch::all();

        foreach ($branches as $branch) {
            $foodItems = MenuItem::forBranch($branch->id)->forCategory('food')->get();
            $drinkItems = MenuItem::forBranch($branch->id)->forCategory('drink')->get();

            $grillStation = KitchenStation::where('branch_id', $branch->id)->where('code', 'GRILL')->first();
            $barStation = KitchenStation::where('branch_id', $branch->id)->where('code', 'BAR')->first();

            if ($grillStation) {
                foreach ($foodItems as $item) {
                    MenuItemStation::create([
                        'menu_item_id' => $item->id,
                        'kitchen_station_id' => $grillStation->id,
                    ]);
                }
            }

            if ($barStation) {
                foreach ($drinkItems as $item) {
                    MenuItemStation::create([
                        'menu_item_id' => $item->id,
                        'kitchen_station_id' => $barStation->id,
                    ]);
                }
            }
        }
    }
}
