<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HousekeepingTest extends TestCase
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

    public function test_can_list_tasks(): void
    {
        Task::factory()->count(3)->create([
            'branch_id' => $this->branch->id,
        ]);

        $response = $this->actingAs($this->user)->get('/housekeeping');

        $response->assertStatus(200);
    }

    public function test_can_create_task(): void
    {
        $response = $this->actingAs($this->user)->post('/housekeeping', [
            'room_id' => $this->room->id,
            'type' => 'cleaning',
            'priority' => 'normal',
            'description' => 'Regular cleaning',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('tasks', [
            'branch_id' => $this->branch->id,
            'room_id' => $this->room->id,
            'type' => 'cleaning',
            'status' => 'pending',
        ]);
    }

    public function test_can_start_task(): void
    {
        $task = Task::factory()->pending()->create([
            'branch_id' => $this->branch->id,
            'room_id' => $this->room->id,
        ]);

        $response = $this->actingAs($this->user)->post("/housekeeping/{$task->id}/start");

        $response->assertRedirect();
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'in_progress',
        ]);
    }

    public function test_can_complete_task(): void
    {
        $task = Task::factory()->inProgress()->create([
            'branch_id' => $this->branch->id,
            'room_id' => $this->room->id,
        ]);

        $response = $this->actingAs($this->user)->post("/housekeeping/{$task->id}/complete", [
            'notes' => 'Completed successfully',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'completed',
        ]);
    }

    public function test_can_assign_task(): void
    {
        $task = Task::factory()->pending()->create([
            'branch_id' => $this->branch->id,
            'room_id' => $this->room->id,
        ]);

        $response = $this->actingAs($this->user)->put("/housekeeping/{$task->id}", [
            'assigned_to' => $this->user->id,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'assigned_to' => $this->user->id,
        ]);
    }

    public function test_can_delete_task(): void
    {
        $task = Task::factory()->create([
            'branch_id' => $this->branch->id,
            'room_id' => $this->room->id,
        ]);

        $response = $this->actingAs($this->user)->delete("/housekeeping/{$task->id}");

        $response->assertRedirect();
        $this->assertSoftDeleted('tasks', ['id' => $task->id]);
    }
}
