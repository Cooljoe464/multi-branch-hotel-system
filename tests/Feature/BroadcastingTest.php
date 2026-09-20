<?php

namespace Tests\Feature;

use App\Events\RoomStatusUpdated;
use App\Models\Branch;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class BroadcastingTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected RoomType $roomType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->roomType = RoomType::factory()->create(['branch_id' => $this->branch->id]);
    }

    public function test_dispatches_room_status_updated_event_on_status_change(): void
    {
        Event::fake([RoomStatusUpdated::class]);

        $room = Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'status' => 'available',
        ]);

        $room->update(['status' => 'occupied']);

        Event::assertDispatched(RoomStatusUpdated::class, function ($event) use ($room) {
            return $event->room->id === $room->id;
        });
    }

    public function test_does_not_dispatch_event_when_status_is_unchanged(): void
    {
        Event::fake([RoomStatusUpdated::class]);

        $room = Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'status' => 'available',
        ]);

        $room->update(['notes' => 'Updated notes']);

        Event::assertNotDispatched(RoomStatusUpdated::class);
    }

    public function test_broadcast_payload_contains_required_room_attributes(): void
    {
        $room = Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'status' => 'occupied',
            'number' => '301',
            'floor' => '3',
            'wing' => 'East',
        ]);

        $event = new RoomStatusUpdated($room);
        $payload = $event->broadcastWith();

        $this->assertArrayHasKey('id', $payload);
        $this->assertArrayHasKey('number', $payload);
        $this->assertArrayHasKey('status', $payload);
        $this->assertArrayHasKey('floor', $payload);
        $this->assertArrayHasKey('wing', $payload);
        $this->assertArrayHasKey('room_type', $payload);
        $this->assertArrayHasKey('updated_at', $payload);
        $this->assertEquals($room->id, $payload['id']);
        $this->assertEquals('301', $payload['number']);
        $this->assertEquals('occupied', $payload['status']);
        $this->assertEquals('3', $payload['floor']);
        $this->assertEquals('East', $payload['wing']);
        $this->assertEquals($room->updated_at->toISOString(), $payload['updated_at']);
    }

    public function test_broadcast_payload_room_type_has_id_name_code(): void
    {
        $room = Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
        ]);

        $event = new RoomStatusUpdated($room);
        $payload = $event->broadcastWith();

        $this->assertArrayHasKey('id', $payload['room_type']);
        $this->assertArrayHasKey('name', $payload['room_type']);
        $this->assertArrayHasKey('code', $payload['room_type']);
        $this->assertEquals($this->roomType->id, $payload['room_type']['id']);
        $this->assertEquals($this->roomType->name, $payload['room_type']['name']);
        $this->assertEquals($this->roomType->code, $payload['room_type']['code']);
    }

    public function test_uses_private_channel_scoped_to_branch(): void
    {
        $room = Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
        ]);

        $event = new RoomStatusUpdated($room);
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertStringContainsString("branch.{$this->branch->id}", $channels[0]->name);
    }

    public function test_broadcast_event_name_is_room_status_updated(): void
    {
        $room = Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
        ]);

        $event = new RoomStatusUpdated($room);

        $this->assertEquals('room.status.updated', $event->broadcastAs());
    }

    public function test_multiple_room_status_changes_dispatch_multiple_events(): void
    {
        Event::fake([RoomStatusUpdated::class]);

        $room = Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'status' => 'available',
        ]);

        $room->update(['status' => 'occupied']);
        $room->update(['status' => 'dirty']);
        $room->update(['status' => 'available']);

        Event::assertDispatched(RoomStatusUpdated::class, 3);
    }

    public function test_room_creation_does_not_broadcast(): void
    {
        Event::fake([RoomStatusUpdated::class]);

        Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
        ]);

        Event::assertNotDispatched(RoomStatusUpdated::class);
    }
}
