<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Database\Seeder;

class RoomSeeder extends Seeder
{
    public function run(): void
    {
        $branches = Branch::all();

        $wings = ['north', 'south', 'east', 'west'];

        foreach ($branches as $branch) {
            $roomTypes = RoomType::where('branch_id', $branch->id)->get();

            if ($roomTypes->isEmpty()) {
                continue;
            }

            $roomNumber = 1;

            for ($floor = 1; $floor <= 4; $floor++) {
                for ($roomOnFloor = 0; $roomOnFloor < 5; $roomOnFloor++) {
                    $roomType = $roomTypes[$roomNumber % $roomTypes->count()] ?? null;

                    if (! $roomType) {
                        $roomNumber++;

                        continue;
                    }

                    $wing = $wings[$floor % count($wings)];

                    $statuses = ['available', 'available', 'available', 'occupied', 'dirty'];
                    $status = $statuses[$roomNumber % count($statuses)];

                    Room::firstOrCreate(
                        [
                            'branch_id' => $branch->id,
                            'number' => (string) ($floor * 100 + $roomOnFloor + 1),
                        ],
                        [
                            'room_type_id' => $roomType->id,
                            'floor' => $floor,
                            'wing' => $wing,
                            'status' => $status,
                            'is_accessible' => $roomOnFloor === 0,
                            'is_smoking' => false,
                            'is_active' => true,
                            'notes' => null,
                        ]
                    );

                    $roomNumber++;
                }
            }
        }
    }
}
