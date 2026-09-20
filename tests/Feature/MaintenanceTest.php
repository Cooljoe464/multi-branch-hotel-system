<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\MaintenanceTicket;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $user;

    protected Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->user = $this->makeAdminUser($this->branch);
        $roomType = RoomType::factory()->create(['branch_id' => $this->branch->id]);
        $this->room = Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $roomType->id,
        ]);
    }

    public function test_can_list_tickets(): void
    {
        MaintenanceTicket::factory()->count(3)->create([
            'branch_id' => $this->branch->id,
        ]);

        $response = $this->actingAs($this->user)->get('/maintenance');

        $response->assertStatus(200);
    }

    public function test_can_create_ticket(): void
    {
        $response = $this->actingAs($this->user)->post('/maintenance', [
            'room_id' => $this->room->id,
            'category' => 'plumbing',
            'priority' => 'normal',
            'title' => 'Leaky faucet',
            'description' => 'The faucet in the bathroom is dripping.',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('maintenance_tickets', [
            'branch_id' => $this->branch->id,
            'room_id' => $this->room->id,
            'category' => 'plumbing',
            'status' => 'open',
        ]);
    }

    public function test_can_start_ticket(): void
    {
        $ticket = MaintenanceTicket::factory()->open()->create([
            'branch_id' => $this->branch->id,
        ]);

        $response = $this->actingAs($this->user)->post("/maintenance/{$ticket->id}/start");

        $response->assertRedirect();
        $this->assertDatabaseHas('maintenance_tickets', [
            'id' => $ticket->id,
            'status' => 'in_progress',
        ]);
    }

    public function test_can_complete_ticket(): void
    {
        $ticket = MaintenanceTicket::factory()->inProgress()->create([
            'branch_id' => $this->branch->id,
        ]);

        $response = $this->actingAs($this->user)->post("/maintenance/{$ticket->id}/complete", [
            'resolution_notes' => 'Faucet replaced',
            'actual_cost' => 150,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('maintenance_tickets', [
            'id' => $ticket->id,
            'status' => 'completed',
            'actual_cost' => 150,
        ]);
    }

    public function test_can_lock_room(): void
    {
        $ticket = MaintenanceTicket::factory()->open()->create([
            'branch_id' => $this->branch->id,
            'room_id' => $this->room->id,
        ]);

        $response = $this->actingAs($this->user)->post("/maintenance/{$ticket->id}/lock-room");

        $response->assertRedirect();
        $this->assertDatabaseHas('maintenance_tickets', [
            'id' => $ticket->id,
            'is_room_locked' => true,
        ]);
    }

    public function test_can_unlock_room(): void
    {
        $ticket = MaintenanceTicket::factory()->locked()->create([
            'branch_id' => $this->branch->id,
            'room_id' => $this->room->id,
        ]);

        $response = $this->actingAs($this->user)->post("/maintenance/{$ticket->id}/unlock-room");

        $response->assertRedirect();
        $this->assertDatabaseHas('maintenance_tickets', [
            'id' => $ticket->id,
            'is_room_locked' => false,
        ]);
    }

    public function test_can_delete_ticket(): void
    {
        $ticket = MaintenanceTicket::factory()->open()->create([
            'branch_id' => $this->branch->id,
        ]);

        $response = $this->actingAs($this->user)->delete("/maintenance/{$ticket->id}");

        $response->assertRedirect();
        $this->assertSoftDeleted('maintenance_tickets', ['id' => $ticket->id]);
    }
}
