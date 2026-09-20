<?php

use App\Models\Branch;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;

uses(DatabaseMigrations::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->branch = Branch::factory()->create();

    $this->admin = User::factory()->create([
        'branch_id' => $this->branch->id,
        'email' => 'globaladmin@test.com',
        'password' => 'password',
        'is_global_admin' => true,
    ]);
    $this->admin->assignRole('Global Admin');
    $this->admin->branches()->syncWithoutDetaching([$this->branch->id]);

    $this->gm = User::factory()->create([
        'branch_id' => $this->branch->id,
        'email' => 'branchgm@test.com',
        'password' => 'password',
    ]);
    $this->gm->assignRole('Branch GM');
    $this->gm->branches()->syncWithoutDetaching([$this->branch->id]);

    $this->housekeeper = User::factory()->create([
        'branch_id' => $this->branch->id,
        'email' => 'housekeeper@test.com',
        'password' => 'password',
    ]);
    $this->housekeeper->assignRole('Housekeeper');
    $this->housekeeper->branches()->syncWithoutDetaching([$this->branch->id]);

    $this->roomType = RoomType::factory()->create([
        'branch_id' => $this->branch->id,
    ]);

    $this->room = Room::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'number' => '101',
    ]);
});

it('loads the housekeeping page via browser', function () {
    visit('/login')
        ->type('email', 'globaladmin@test.com')
        ->type('password', 'password')
        ->press('Log in')
        ->wait(3);

    visit('/housekeeping')
        ->wait(2)
        ->assertSee('Housekeeping');
});

it('creates a housekeeping task via HTTP as global admin', function () {
    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post('/housekeeping', [
            'room_id' => $this->room->id,
            'type' => 'cleaning',
            'priority' => 'normal',
            'description' => 'Regular cleaning',
            'estimated_minutes' => 30,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('tasks', [
        'branch_id' => $this->branch->id,
        'room_id' => $this->room->id,
        'type' => 'cleaning',
        'priority' => 'normal',
        'status' => 'pending',
    ]);
});

it('starts a housekeeping task via HTTP as global admin', function () {
    $task = Task::factory()->pending()->create([
        'branch_id' => $this->branch->id,
        'room_id' => $this->room->id,
    ]);

    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post("/housekeeping/{$task->id}/start")
        ->assertRedirect();

    $this->assertDatabaseHas('tasks', [
        'id' => $task->id,
        'status' => 'in_progress',
    ]);
});

it('completes a housekeeping task via HTTP as global admin', function () {
    $task = Task::factory()->inProgress()->create([
        'branch_id' => $this->branch->id,
        'room_id' => $this->room->id,
    ]);

    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post("/housekeeping/{$task->id}/complete", [
            'notes' => 'Room cleaned successfully',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('tasks', [
        'id' => $task->id,
        'status' => 'completed',
    ]);
});

it('updates a housekeeping task via HTTP as global admin', function () {
    $task = Task::factory()->pending()->create([
        'branch_id' => $this->branch->id,
        'room_id' => $this->room->id,
        'priority' => 'low',
    ]);

    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->put("/housekeeping/{$task->id}", [
            'priority' => 'high',
            'notes' => 'Priority increased',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('tasks', [
        'id' => $task->id,
        'priority' => 'high',
    ]);
});

it('creates a housekeeping task via HTTP as housekeeper', function () {
    $this->actingAs($this->housekeeper)
        ->withSession(['branch_id' => $this->branch->id])
        ->post('/housekeeping', [
            'room_id' => $this->room->id,
            'type' => 'inspection',
            'priority' => 'high',
            'estimated_minutes' => 15,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('tasks', [
        'branch_id' => $this->branch->id,
        'type' => 'inspection',
    ]);
});

it('shows housekeeping list with data', function () {
    Task::factory()->pending()->create([
        'branch_id' => $this->branch->id,
        'room_id' => $this->room->id,
    ]);

    $this->actingAs($this->admin)
        ->get('/housekeeping')
        ->assertOk();
});
