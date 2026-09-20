<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use Carbon\Carbon;

class OccupancyService
{
    public function getPercentage(int $branchId, ?RoomType $roomType = null, Carbon|string|null $date = null): float
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date ?? now());
        $dateStr = $date->toDateString();

        $roomQuery = Room::forBranch($branchId)
            ->where('is_active', true)
            ->where('status', '!=', 'out_of_order');

        if ($roomType) {
            $roomQuery->where('room_type_id', $roomType->id);
        }

        $totalRooms = $roomQuery->count();

        if ($totalRooms === 0) {
            return 0.0;
        }

        $occupiedQuery = Reservation::forBranch($branchId)
            ->checkedIn()
            ->where('check_in_date', '<=', $dateStr)
            ->where('check_out_date', '>', $dateStr);

        if ($roomType) {
            $occupiedQuery->where('room_type_id', $roomType->id);
        }

        $occupiedRooms = $occupiedQuery->count();

        return round(($occupiedRooms / $totalRooms) * 100, 1);
    }
}
