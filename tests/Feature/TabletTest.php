<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\MenuItem;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\TabletOrder;
use App\Models\TabletSession;
use App\Models\User;
use App\Services\TabletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TabletTest extends TestCase
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

    public function test_can_pair_tablet_to_room(): void
    {
        $roomType = RoomType::factory()->create(['branch_id' => $this->branch->id]);
        $room = Room::factory()->create(['branch_id' => $this->branch->id, 'room_type_id' => $roomType->id]);
        $reservation = Reservation::factory()->forBranch($this->branch->id)->create([
            'room_id' => $room->id,
            'room_type_id' => $roomType->id,
        ]);

        $response = $this->actingAs($this->user)->post('/tablet/pair', [
            'room_id' => $room->id,
            'reservation_id' => $reservation->id,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('tablet_sessions', [
            'branch_id' => $this->branch->id,
            'room_id' => $room->id,
            'reservation_id' => $reservation->id,
        ]);
    }

    public function test_can_unpair_tablet(): void
    {
        $roomType = RoomType::factory()->create(['branch_id' => $this->branch->id]);
        $room = Room::factory()->create(['branch_id' => $this->branch->id, 'room_type_id' => $roomType->id]);
        $reservation = Reservation::factory()->forBranch($this->branch->id)->create([
            'room_id' => $room->id,
            'room_type_id' => $roomType->id,
        ]);

        (new TabletService)->pair($room, $reservation);

        $response = $this->actingAs($this->user)->post('/tablet/unpair', [
            'room_id' => $room->id,
        ]);

        $response->assertRedirect();
        $session = TabletSession::where('room_id', $room->id)->first();
        $this->assertNotNull($session->wiped_at);
    }

    public function test_can_wipe_tablet_session(): void
    {
        $roomType = RoomType::factory()->create(['branch_id' => $this->branch->id]);
        $room = Room::factory()->create(['branch_id' => $this->branch->id, 'room_type_id' => $roomType->id]);
        $reservation = Reservation::factory()->forBranch($this->branch->id)->create([
            'room_id' => $room->id,
            'room_type_id' => $roomType->id,
        ]);

        $session = (new TabletService)->pair($room, $reservation);

        $response = $this->actingAs($this->user)->post('/tablet/wipe', [
            'tablet_session_id' => $session->id,
        ]);

        $response->assertRedirect();
        $session->refresh();
        $this->assertNotNull($session->wiped_at);
    }

    public function test_can_create_tablet_order(): void
    {
        $roomType = RoomType::factory()->create(['branch_id' => $this->branch->id]);
        $room = Room::factory()->create(['branch_id' => $this->branch->id, 'room_type_id' => $roomType->id]);
        $reservation = Reservation::factory()->forBranch($this->branch->id)->create([
            'room_id' => $room->id,
            'room_type_id' => $roomType->id,
        ]);

        $session = (new TabletService)->pair($room, $reservation);

        $menuItem = MenuItem::factory()->create(['branch_id' => $this->branch->id, 'price' => 2500]);

        $response = $this->actingAs($this->user)->post('/tablet/orders', [
            'session_id' => $session->id,
            'items' => [
                ['menu_item_id' => $menuItem->id, 'quantity' => 2],
            ],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('tablet_orders', [
            'branch_id' => $this->branch->id,
            'tablet_session_id' => $session->id,
            'reservation_id' => $reservation->id,
        ]);
    }

    public function test_can_cancel_tablet_order(): void
    {
        $roomType = RoomType::factory()->create(['branch_id' => $this->branch->id]);
        $room = Room::factory()->create(['branch_id' => $this->branch->id, 'room_type_id' => $roomType->id]);
        $reservation = Reservation::factory()->forBranch($this->branch->id)->create([
            'room_id' => $room->id,
            'room_type_id' => $roomType->id,
        ]);

        $session = (new TabletService)->pair($room, $reservation);
        $menuItem = MenuItem::factory()->create(['branch_id' => $this->branch->id, 'price' => 2500]);

        $this->actingAs($this->user)->post('/tablet/orders', [
            'session_id' => $session->id,
            'items' => [
                ['menu_item_id' => $menuItem->id, 'quantity' => 1],
            ],
        ]);

        $order = TabletOrder::where('tablet_session_id', $session->id)->first();

        $response = $this->actingAs($this->user)->post("/tablet/orders/{$order->id}/cancel");

        $response->assertRedirect();
        $this->assertDatabaseHas('tablet_orders', [
            'id' => $order->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_validates_required_fields_for_pair(): void
    {
        $response = $this->actingAs($this->user)->post('/tablet/pair', []);

        $response->assertSessionHasErrors(['room_id', 'reservation_id']);
    }

    public function test_validates_required_fields_for_order(): void
    {
        $response = $this->actingAs($this->user)->post('/tablet/orders', []);

        $response->assertSessionHasErrors(['session_id', 'items']);
    }
}
