<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\LaundryOrder;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LaundryTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::factory()->create();
        $this->user = $this->makeAdminUser($this->branch);
    }

    public function test_can_list_laundry_orders(): void
    {
        $roomType = RoomType::factory()->create(['branch_id' => $this->branch->id]);
        $room = Room::factory()->create(['branch_id' => $this->branch->id, 'room_type_id' => $roomType->id]);
        $reservation = Reservation::factory()->create(['branch_id' => $this->branch->id, 'room_id' => $room->id, 'room_type_id' => $roomType->id]);
        LaundryOrder::factory()->forBranch($this->branch->id)->create(['reservation_id' => $reservation->id]);

        $response = $this->actingAs($this->user)->get('/laundry');
        $response->assertStatus(200);
    }

    public function test_can_pickup_laundry(): void
    {
        $roomType = RoomType::factory()->create(['branch_id' => $this->branch->id]);
        $room = Room::factory()->create(['branch_id' => $this->branch->id, 'room_type_id' => $roomType->id]);
        $reservation = Reservation::factory()->create(['branch_id' => $this->branch->id, 'room_id' => $room->id, 'room_type_id' => $roomType->id]);
        $order = LaundryOrder::factory()->forBranch($this->branch->id)->pending()->create(['reservation_id' => $reservation->id]);

        $response = $this->actingAs($this->user)->post("/laundry/{$order->id}/pickup");
        $response->assertRedirect();
        $this->assertDatabaseHas('laundry_orders', ['id' => $order->id, 'status' => 'picked_up']);
    }
}
