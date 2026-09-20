<?php

namespace Tests\Feature;

use App\Events\KotItemStatusUpdated;
use App\Events\MenuItemStockToggled;
use App\Events\OrderStatusUpdated;
use App\Events\RoomStatusUpdated;
use App\Models\Branch;
use App\Models\KotItem;
use App\Models\MenuItem;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\TabletOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ReverbBroadcastingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->roomType = RoomType::factory()->create(['branch_id' => $this->branch->id]);
    }

    public function test_reverb_driver_is_configured_for_local_broadcasting(): void
    {
        config(['broadcasting.default' => 'reverb']);
        config(['broadcasting.connections.reverb.driver' => 'reverb']);
        config(['reverb.apps.apps.0.key' => 'test-key']);
        config(['reverb.apps.apps.0.secret' => 'test-secret']);

        $this->assertSame('reverb', config('broadcasting.default'));
        $this->assertSame('reverb', config('broadcasting.connections.reverb.driver'));
        $this->assertNotNull(config('reverb.apps.apps.0.key'));
        $this->assertNotNull(config('reverb.apps.apps.0.secret'));
    }

    public function test_room_status_event_uses_private_branch_channel(): void
    {
        $room = Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
        ]);

        $event = new RoomStatusUpdated($room);

        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertStringStartsWith('private-branch.', $channels[0]->name);
        $this->assertStringEndsWith((string) $this->branch->id, $channels[0]->name);
    }

    public function test_room_status_event_payload_is_serialized_for_reverb(): void
    {
        $room = Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'status' => 'occupied',
            'number' => '301',
            'floor' => '3',
            'wing' => 'East',
        ]);

        $payload = (new RoomStatusUpdated($room))->broadcastWith();

        $this->assertSame('room.status.updated', $payload['id'] === $room->id ? 'room.status.updated' : '');
        $this->assertSame('room.status.updated', (new RoomStatusUpdated($room))->broadcastAs());
        $this->assertSame($room->id, $payload['id']);
        $this->assertSame('301', $payload['number']);
        $this->assertSame('occupied', $payload['status']);
        $this->assertIsArray($payload['room_type']);
        $this->assertArrayHasKey('updated_at', $payload);
    }

    public function test_kot_item_status_event_uses_branch_kds_channel(): void
    {
        $kotItem = KotItem::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'pending',
            'item_name' => 'Burger',
            'quantity' => 2,
            'priority' => 'normal',
            'outlet' => 'Main Kitchen',
        ]);

        $event = new KotItemStatusUpdated($kotItem, 'pending');

        $this->assertSame('branch.'.$kotItem->branch_id.'.kds', $event->broadcastOn()->name);
        $this->assertSame('kot.updated', $event->broadcastAs());

        $payload = $event->broadcastWith();

        $this->assertSame($kotItem->id, $payload['id']);
        $this->assertSame('Burger', $payload['item_name']);
        $this->assertSame(2, $payload['quantity']);
        $this->assertSame('pending', $payload['previous_status']);
        $this->assertSame('Main Kitchen', $payload['outlet']);
    }

    public function test_menu_item_stock_event_uses_branch_menu_channel(): void
    {
        $menuItem = MenuItem::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Test Item',
            'is_available' => true,
        ]);

        $event = new MenuItemStockToggled($menuItem, false);

        $this->assertSame('branch.'.$menuItem->branch_id.'.menu', $event->broadcastOn()->name);
        $this->assertSame('menu.stock.toggled', $event->broadcastAs());

        $payload = $event->broadcastWith();

        $this->assertSame($menuItem->id, $payload['id']);
        $this->assertSame('Test Item', $payload['name']);
        $this->assertFalse($payload['is_available']);
    }

    public function test_order_status_event_uses_tablet_order_channel(): void
    {
        $order = TabletOrder::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'pending',
            'payment_status' => 'unpaid',
        ]);

        $event = new OrderStatusUpdated($order, 'pending');

        $this->assertSame('tablet.order.'.$order->id, $event->broadcastOn()->name);
        $this->assertSame('order.status.updated', $event->broadcastAs());

        $payload = $event->broadcastWith();

        $this->assertSame($order->id, $payload['id']);
        $this->assertSame('pending', $payload['previous_status']);
        $this->assertSame('unpaid', $payload['payment_status']);
    }

    public function test_broadcast_events_are_queued_for_reverb_delivery(): void
    {
        $room = Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
        ]);

        Event::fake([RoomStatusUpdated::class]);

        $room->update(['status' => 'dirty']);

        Event::assertDispatched(RoomStatusUpdated::class, function ($event) use ($room) {
            return $event->room->id === $room->id;
        });
    }

    public function test_reverb_event_payloads_do_not_leak_cross_branch_data(): void
    {
        $room = Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
        ]);
        $otherBranch = Branch::factory()->create();
        $otherRoom = Room::factory()->create([
            'branch_id' => $otherBranch->id,
            'room_type_id' => RoomType::factory()->create(['branch_id' => $otherBranch->id])->id,
        ]);

        $payload = (new RoomStatusUpdated($room))->broadcastWith();

        $this->assertNotContains($otherRoom->id, $payload);
        $this->assertStringNotContainsString((string) $otherBranch->id, (new RoomStatusUpdated($room))->broadcastOn()[0]->name);
    }
}
