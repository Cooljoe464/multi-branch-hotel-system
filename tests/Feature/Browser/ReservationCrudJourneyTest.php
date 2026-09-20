<?php

use App\Models\Branch;
use App\Models\Reservation;
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
        'name' => 'Standard',
        'base_rate' => 50000,
    ]);

    $this->room = Room::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'number' => '101',
        'status' => 'available',
    ]);
});

it('loads the reservations page via browser', function () {
    visit('/login')
        ->type('email', 'globaladmin@test.com')
        ->type('password', 'password')
        ->press('Log in')
        ->wait(3);

    visit('/reservations')
        ->wait(2)
        ->assertSee('Reservations');
});

it('creates a reservation via HTTP as global admin', function () {
    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post('/reservations', [
            'room_type_id' => $this->roomType->id,
            'guest_name' => 'John Guest',
            'guest_email' => 'john@example.com',
            'guest_phone' => '+2348012345678',
            'adults' => 2,
            'children' => 0,
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(3)->toDateString(),
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('reservations', [
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'guest_name' => 'John Guest',
        'status' => 'confirmed',
    ]);
});

it('updates a reservation via HTTP as global admin', function () {
    $reservation = Reservation::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'guest_name' => 'Old Name',
        'status' => 'reserved',
    ]);

    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->put("/reservations/{$reservation->id}", [
            'guest_name' => 'Updated Name',
            'adults' => 1,
            'children' => 1,
            'check_in_date' => $reservation->check_in_date,
            'check_out_date' => $reservation->check_out_date,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('reservations', [
        'id' => $reservation->id,
        'guest_name' => 'Updated Name',
        'adults' => 1,
        'children' => 1,
    ]);
});

it('checks in a reservation via HTTP as global admin', function () {
    $reservation = Reservation::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'room_id' => $this->room->id,
        'status' => 'reserved',
        'check_in_date' => now()->toDateString(),
    ]);

    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post("/reservations/{$reservation->id}/check-in", [
            'room_id' => $this->room->id,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('reservations', [
        'id' => $reservation->id,
        'status' => 'checked_in',
    ]);
});

it('checks out a reservation via HTTP as global admin', function () {
    $reservation = Reservation::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'room_id' => $this->room->id,
        'status' => 'checked_in',
    ]);

    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post("/reservations/{$reservation->id}/check-out")
        ->assertRedirect();

    $this->assertDatabaseHas('reservations', [
        'id' => $reservation->id,
        'status' => 'checked_out',
    ]);
});

it('cancels a reservation via HTTP as global admin', function () {
    $reservation = Reservation::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'status' => 'reserved',
    ]);

    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post("/reservations/{$reservation->id}/cancel")
        ->assertRedirect();

    $this->assertDatabaseHas('reservations', [
        'id' => $reservation->id,
        'status' => 'cancelled',
    ]);
});

it('creates a reservation via HTTP as front desk', function () {
    $this->actingAs($this->frontDesk)
        ->withSession(['branch_id' => $this->branch->id])
        ->post('/reservations', [
            'room_type_id' => $this->roomType->id,
            'guest_name' => 'FD Guest',
            'adults' => 1,
            'children' => 0,
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('reservations', [
        'branch_id' => $this->branch->id,
        'guest_name' => 'FD Guest',
    ]);
});

it('shows reservations list with data', function () {
    Reservation::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'guest_name' => 'Listed Guest',
    ]);

    $this->actingAs($this->admin)
        ->get('/reservations')
        ->assertOk();
});
