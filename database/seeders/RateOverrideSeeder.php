<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\RateOverride;
use App\Models\RoomType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class RateOverrideSeeder extends Seeder
{
    public function run(): void
    {
        $branches = Branch::all();

        foreach ($branches as $branch) {
            $roomTypes = RoomType::where('branch_id', $branch->id)->get();

            if ($roomTypes->isEmpty()) {
                continue;
            }

            $this->createOverrides($branch, $roomTypes);
        }
    }

    /** @param  Collection<int, RoomType>  $roomTypes */
    private function createOverrides(Branch $branch, Collection $roomTypes): void
    {
        $periods = [
            [
                'name' => 'Christmas & New Year',
                'days_from_now' => 14,
                'duration' => 14,
                'rate_increase' => 1.3,
            ],
            [
                'name' => 'Easter Holiday',
                'days_from_now' => 30,
                'duration' => 5,
                'rate_increase' => 1.25,
            ],
            [
                'name' => 'Eid el-Fitr',
                'days_from_now' => 45,
                'duration' => 3,
                'rate_increase' => 1.2,
            ],
            [
                'name' => 'Lagos Fashion Week',
                'days_from_now' => 60,
                'duration' => 7,
                'rate_increase' => 1.4,
            ],
            [
                'name' => 'Off-Peak Season',
                'days_from_now' => -15,
                'duration' => 10,
                'rate_increase' => 0.85,
            ],
        ];

        foreach ($roomTypes as $roomType) {
            foreach ($periods as $period) {
                $startDate = now()->addDays($period['days_from_now'])->toDateString();
                $endDate = now()->addDays($period['days_from_now'] + $period['duration'])->toDateString();

                $overrideRate = (int) ($roomType->base_rate * $period['rate_increase']);

                RateOverride::firstOrCreate(
                    [
                        'branch_id' => $branch->id,
                        'room_type_id' => $roomType->id,
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                    ],
                    [
                        'rate_override' => $overrideRate,
                        'mlos' => $period['rate_increase'] > 1.3 ? 3 : null,
                        'cta' => $period['rate_increase'] >= 1.4,
                        'ctd' => false,
                        'is_active' => true,
                        'notes' => "{$period['name']} rate adjustment",
                    ]
                );
            }
        }
    }
}
