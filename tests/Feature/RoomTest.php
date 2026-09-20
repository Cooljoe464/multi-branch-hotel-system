<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoomTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $user;

    protected RoomType $roomType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->user = $this->makeAdminUser($this->branch);
        $this->roomType = RoomType::factory()->create(['branch_id' => $this->branch->id]);
    }

    public function test_can_list_rooms(): void
    {
        Room::factory()->count(3)->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
        ]);

        $response = $this->actingAs($this->user)->get('/rooms');

        $response->assertStatus(200);
    }

    public function test_can_create_room(): void
    {
        $response = $this->actingAs($this->user)->post('/rooms', [
            'room_type_id' => $this->roomType->id,
            'number' => '101',
            'floor' => '1',
            'wing' => 'north',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('rooms', [
            'branch_id' => $this->branch->id,
            'number' => '101',
            'status' => 'available',
        ]);
    }

    public function test_can_update_room(): void
    {
        $room = Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
        ]);

        $response = $this->actingAs($this->user)->put("/rooms/{$room->id}", [
            'floor' => '5',
            'wing' => 'south',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('rooms', [
            'id' => $room->id,
            'floor' => '5',
            'wing' => 'south',
        ]);
    }

    public function test_can_update_room_status(): void
    {
        $room = Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'status' => 'available',
        ]);

        $response = $this->actingAs($this->user)->patch("/rooms/{$room->id}/status", [
            'status' => 'dirty',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('rooms', [
            'id' => $room->id,
            'status' => 'dirty',
        ]);
    }

    public function test_can_delete_room(): void
    {
        $room = Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
        ]);

        $response = $this->actingAs($this->user)->delete("/rooms/{$room->id}");

        $response->assertRedirect();
        $this->assertSoftDeleted('rooms', ['id' => $room->id]);
    }

    public function test_can_filter_rooms_by_status(): void
    {
        Room::factory()->available()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
        ]);
        Room::factory()->occupied()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
        ]);

        $response = $this->actingAs($this->user)->get('/rooms?status=available');

        $response->assertStatus(200);
    }
}
