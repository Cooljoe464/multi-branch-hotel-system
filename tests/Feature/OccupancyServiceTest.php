<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\OccupancyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OccupancyServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected RoomType $roomType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->roomType = RoomType::factory()->create([
            'branch_id' => $this->branch->id,
        ]);
    }

    public function test_returns_zero_when_no_rooms(): void
    {
        $service = new OccupancyService;
        $result = $service->getPercentage($this->branch->id);

        $this->assertEquals(0.0, $result);
    }

    public function test_calculates_basic_occupancy(): void
    {
        Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'status' => 'available',
        ]);
        Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'status' => 'available',
        ]);

        Reservation::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'status' => 'checked_in',
            'check_in_date' => now()->toDateString(),
            'check_out_date' => now()->addDay()->toDateString(),
        ]);

        $service = new OccupancyService;
        $result = $service->getPercentage($this->branch->id);

        $this->assertEquals(50.0, $result);
    }

    public function test_excludes_out_of_order_rooms(): void
    {
        Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'status' => 'available',
        ]);
        Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'status' => 'out_of_order',
        ]);

        Reservation::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'status' => 'checked_in',
            'check_in_date' => now()->toDateString(),
            'check_out_date' => now()->addDay()->toDateString(),
        ]);

        $service = new OccupancyService;
        $result = $service->getPercentage($this->branch->id);

        // 1 occupied / 1 active room = 100%
        $this->assertEquals(100.0, $result);
    }

    public function test_filters_by_room_type(): void
    {
        $otherRoomType = RoomType::factory()->create([
            'branch_id' => $this->branch->id,
        ]);

        Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'status' => 'available',
        ]);
        Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $otherRoomType->id,
            'status' => 'available',
        ]);

        Reservation::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'status' => 'checked_in',
            'check_in_date' => now()->toDateString(),
            'check_out_date' => now()->addDay()->toDateString(),
        ]);

        $service = new OccupancyService;
        $result = $service->getPercentage($this->branch->id, $this->roomType);

        // 1 occupied / 1 room of this type = 100%
        $this->assertEquals(100.0, $result);
    }

    public function test_returns_float_with_one_decimal(): void
    {
        for ($i = 0; $i < 3; $i++) {
            Room::factory()->create([
                'branch_id' => $this->branch->id,
                'room_type_id' => $this->roomType->id,
                'status' => 'available',
            ]);
        }

        Reservation::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'status' => 'checked_in',
            'check_in_date' => now()->toDateString(),
            'check_out_date' => now()->addDay()->toDateString(),
        ]);

        $service = new OccupancyService;
        $result = $service->getPercentage($this->branch->id);

        $this->assertEquals(33.3, $result);
    }
}
