<?php

use App\Models\Branch;
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

    $this->gm = User::factory()->create([
        'branch_id' => $this->branch->id,
        'email' => 'branchgm@test.com',
        'password' => 'password',
    ]);
    $this->gm->assignRole('Branch GM');
    $this->gm->branches()->syncWithoutDetaching([$this->branch->id]);

    $this->roomType = RoomType::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Deluxe',
    ]);
});

it('loads the rooms page via browser', function () {
    visit('/login')
        ->type('email', 'globaladmin@test.com')
        ->type('password', 'password')
        ->press('Log in')
        ->wait(3);

    visit('/rooms')
        ->wait(2)
        ->assertSee('Rooms');
});

it('creates a room via HTTP as global admin', function () {
    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post('/rooms', [
            'room_type_id' => $this->roomType->id,
            'number' => '201',
            'floor' => '2',
            'wing' => 'East',
            'is_accessible' => false,
            'is_smoking' => false,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('rooms', [
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'number' => '201',
        'status' => 'available',
    ]);
});

it('updates a room via HTTP as global admin', function () {
    $room = Room::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'number' => '301',
        'floor' => '3',
    ]);

    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->put("/rooms/{$room->id}", [
            'room_type_id' => $this->roomType->id,
            'floor' => '4',
            'wing' => 'West',
            'is_accessible' => true,
            'is_smoking' => false,
            'is_active' => true,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('rooms', [
        'id' => $room->id,
        'floor' => '4',
        'wing' => 'West',
        'is_accessible' => true,
    ]);
});

it('updates room status via HTTP as global admin', function () {
    $room = Room::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'status' => 'available',
    ]);

    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->patch("/rooms/{$room->id}/status", [
            'status' => 'out_of_order',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('rooms', [
        'id' => $room->id,
        'status' => 'out_of_order',
    ]);
});

it('denies branch gm access to create rooms', function () {
    $this->actingAs($this->gm)
        ->withSession(['branch_id' => $this->branch->id])
        ->post('/rooms', [
            'room_type_id' => $this->roomType->id,
            'number' => '401',
            'floor' => '4',
        ])
        ->assertForbidden();
});

it('shows rooms list with data', function () {
    Room::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'number' => '501',
    ]);

    $this->actingAs($this->admin)
        ->get('/rooms')
        ->assertOk();
});
