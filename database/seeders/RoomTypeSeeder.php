<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\RoomType;
use Illuminate\Database\Seeder;

class RoomTypeSeeder extends Seeder
{
    public function run(): void
    {
        $branches = Branch::all();

        $roomTypes = [
            [
                'name' => 'Standard Room',
                'code' => 'STD',
                'description' => 'Comfortable room with essential amenities for a pleasant stay.',
                'base_rate' => 25000,
                'max_occupancy' => 2,
                'bed_count' => 1,
                'bed_type' => 'queen',
                'is_active' => true,
                'amenities' => ['wifi', 'tv', 'minibar', 'safe'],
            ],
            [
                'name' => 'Deluxe Room',
                'code' => 'DLX',
                'description' => 'Spacious room with premium furnishings and city views.',
                'base_rate' => 40000,
                'max_occupancy' => 2,
                'bed_count' => 1,
                'bed_type' => 'king',
                'is_active' => true,
                'amenities' => ['wifi', 'tv', 'minibar', 'safe', 'coffee_maker', 'bathrobe'],
            ],
            [
                'name' => 'Junior Suite',
                'code' => 'JRS',
                'description' => 'Elegant suite with separate living area and premium amenities.',
                'base_rate' => 65000,
                'max_occupancy' => 3,
                'bed_count' => 1,
                'bed_type' => 'king',
                'is_active' => true,
                'amenities' => ['wifi', 'tv', 'minibar', 'safe', 'coffee_maker', 'bathrobe', 'sofa', 'work_desk'],
            ],
            [
                'name' => 'Executive Suite',
                'code' => 'EXS',
                'description' => 'Luxurious suite with separate bedroom, living room, and executive lounge access.',
                'base_rate' => 95000,
                'max_occupancy' => 4,
                'bed_count' => 2,
                'bed_type' => 'king',
                'is_active' => true,
                'amenities' => ['wifi', 'tv', 'minibar', 'safe', 'coffee_maker', 'bathrobe', 'sofa', 'work_desk', 'lounge_access', 'butler_service'],
            ],
            [
                'name' => 'Presidential Suite',
                'code' => 'PRE',
                'description' => 'The ultimate luxury experience with panoramic views and personalized service.',
                'base_rate' => 150000,
                'max_occupancy' => 4,
                'bed_count' => 2,
                'bed_type' => 'king',
                'is_active' => true,
                'amenities' => ['wifi', 'tv', 'minibar', 'safe', 'coffee_maker', 'bathrobe', 'sofa', 'work_desk', 'lounge_access', 'butler_service', 'private_pool', 'dining_room'],
            ],
        ];

        foreach ($branches as $branch) {
            foreach ($roomTypes as $index => $type) {
                RoomType::firstOrCreate(
                    [
                        'branch_id' => $branch->id,
                        'code' => "{$type['code']}-".($index + 1),
                    ],
                    array_merge($type, [
                        'branch_id' => $branch->id,
                    ])
                );
            }
        }
    }
}
