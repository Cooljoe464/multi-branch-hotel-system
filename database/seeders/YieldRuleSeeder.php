<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\RoomType;
use App\Models\YieldRule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class YieldRuleSeeder extends Seeder
{
    public function run(): void
    {
        $branches = Branch::all();

        foreach ($branches as $branch) {
            $roomTypes = RoomType::where('branch_id', $branch->id)->get();

            if ($roomTypes->isEmpty()) {
                continue;
            }

            $this->createRules($branch, $roomTypes);
        }
    }

    /** @param  Collection<int, RoomType>  $roomTypes */
    private function createRules(Branch $branch, Collection $roomTypes): void
    {
        $tiers = [
            [
                'min_occupancy' => 0,
                'max_occupancy' => 30,
                'multiplier' => 0.85,
                'priority' => 1,
                'description' => 'Low occupancy - discount rates',
            ],
            [
                'min_occupancy' => 31,
                'max_occupancy' => 50,
                'multiplier' => 1.0,
                'priority' => 2,
                'description' => 'Normal occupancy - standard rates',
            ],
            [
                'min_occupancy' => 51,
                'max_occupancy' => 70,
                'multiplier' => 1.15,
                'priority' => 3,
                'description' => 'Moderate occupancy - slight increase',
            ],
            [
                'min_occupancy' => 71,
                'max_occupancy' => 85,
                'multiplier' => 1.3,
                'priority' => 4,
                'description' => 'High occupancy - premium rates',
            ],
            [
                'min_occupancy' => 86,
                'max_occupancy' => 100,
                'multiplier' => 1.5,
                'priority' => 5,
                'description' => 'Very high occupancy - maximum rates',
            ],
        ];

        foreach ($roomTypes as $roomType) {
            foreach ($tiers as $tier) {
                YieldRule::firstOrCreate(
                    [
                        'branch_id' => $branch->id,
                        'room_type_id' => $roomType->id,
                        'min_occupancy_pct' => $tier['min_occupancy'],
                        'max_occupancy_pct' => $tier['max_occupancy'],
                    ],
                    [
                        'rate_multiplier' => $tier['multiplier'],
                        'mlos_override' => $tier['multiplier'] >= 1.3 ? 2 : null,
                        'cta_override' => $tier['multiplier'] >= 1.4,
                        'is_active' => true,
                        'priority' => $tier['priority'],
                    ]
                );
            }
        }
    }
}
