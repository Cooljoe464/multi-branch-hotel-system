<?php

use App\Models\Branch;
use App\Models\MaintenanceTicket;
use App\Models\Room;
use App\Models\RoomType;
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

    $this->frontDesk = User::factory()->create([
        'branch_id' => $this->branch->id,
        'email' => 'frontdesk@test.com',
        'password' => 'password',
    ]);
    $this->frontDesk->assignRole('Front Desk');
    $this->frontDesk->branches()->syncWithoutDetaching([$this->branch->id]);

    $this->roomType = RoomType::factory()->create([
        'branch_id' => $this->branch->id,
    ]);

    $this->room = Room::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'number' => '101',
    ]);
});

it('loads the maintenance page via browser', function () {
    visit('/login')
        ->type('email', 'globaladmin@test.com')
        ->type('password', 'password')
        ->press('Log in')
        ->wait(3);

    visit('/maintenance')
        ->wait(2)
        ->assertSee('Maintenance');
});

it('creates a maintenance ticket via HTTP as global admin', function () {
    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post('/maintenance', [
            'room_id' => $this->room->id,
            'category' => 'plumbing',
            'priority' => 'high',
            'title' => 'Leaking faucet',
            'description' => 'The bathroom faucet is leaking',
            'estimated_cost' => 5000,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('maintenance_tickets', [
        'branch_id' => $this->branch->id,
        'room_id' => $this->room->id,
        'category' => 'plumbing',
        'title' => 'Leaking faucet',
        'status' => 'open',
    ]);
});

it('starts a maintenance ticket via HTTP as global admin', function () {
    $ticket = MaintenanceTicket::factory()->open()->create([
        'branch_id' => $this->branch->id,
        'room_id' => $this->room->id,
    ]);

    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post("/maintenance/{$ticket->id}/start")
        ->assertRedirect();

    $this->assertDatabaseHas('maintenance_tickets', [
        'id' => $ticket->id,
        'status' => 'in_progress',
    ]);
});

it('completes a maintenance ticket via HTTP as global admin', function () {
    $ticket = MaintenanceTicket::factory()->inProgress()->create([
        'branch_id' => $this->branch->id,
        'room_id' => $this->room->id,
    ]);

    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post("/maintenance/{$ticket->id}/complete", [
            'resolution_notes' => 'Faucet replaced',
            'actual_cost' => 4500,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('maintenance_tickets', [
        'id' => $ticket->id,
        'status' => 'completed',
        'resolution_notes' => 'Faucet replaced',
        'actual_cost' => 4500,
    ]);
});

it('updates a maintenance ticket via HTTP as global admin', function () {
    $ticket = MaintenanceTicket::factory()->open()->create([
        'branch_id' => $this->branch->id,
        'room_id' => $this->room->id,
        'priority' => 'low',
    ]);

    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->put("/maintenance/{$ticket->id}", [
            'priority' => 'urgent',
            'title' => 'Updated title',
            'description' => 'Updated description',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('maintenance_tickets', [
        'id' => $ticket->id,
        'priority' => 'urgent',
        'title' => 'Updated title',
    ]);
});

it('creates a maintenance ticket via HTTP as front desk', function () {
    $this->actingAs($this->frontDesk)
        ->withSession(['branch_id' => $this->branch->id])
        ->post('/maintenance', [
            'room_id' => $this->room->id,
            'category' => 'electrical',
            'priority' => 'normal',
            'title' => 'Light not working',
            'description' => 'Bedroom light not turning on',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('maintenance_tickets', [
        'branch_id' => $this->branch->id,
        'category' => 'electrical',
        'title' => 'Light not working',
    ]);
});

it('shows maintenance list with data', function () {
    MaintenanceTicket::factory()->open()->create([
        'branch_id' => $this->branch->id,
        'room_id' => $this->room->id,
        'title' => 'Listed Ticket',
    ]);

    $this->actingAs($this->admin)
        ->get('/maintenance')
        ->assertOk();
});
