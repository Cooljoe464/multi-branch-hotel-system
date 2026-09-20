<?php

use App\Models\Branch;
use App\Models\Reservation;
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

    $this->roomType = RoomType::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Standard',
    ]);
});

it('loads the CRS page via browser', function () {
    visit('/login')
        ->type('email', 'globaladmin@test.com')
        ->type('password', 'password')
        ->press('Log in')
        ->wait(3);

    visit('/crs')
        ->wait(2)
        ->assertSee('CRS');
});

it('creates a CRS reservation via HTTP as global admin', function () {
    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post('/crs/reservations', [
            'branch_id' => $this->branch->id,
            'guest_name' => 'John Smith',
            'guest_email' => 'john@crs.com',
            'guest_phone' => '+2348012345678',
            'room_type_id' => $this->roomType->id,
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(3)->toDateString(),
            'adults' => 2,
            'children' => 0,
            'room_rate' => 50000,
            'special_requests' => 'Late check-in',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('reservations', [
        'branch_id' => $this->branch->id,
        'source' => 'crs',
        'status' => 'confirmed',
    ]);
});

it('creates a CRS reservation without optional fields', function () {
    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post('/crs/reservations', [
            'branch_id' => $this->branch->id,
            'guest_name' => 'Jane Doe',
            'guest_email' => 'jane@crs.com',
            'room_type_id' => $this->roomType->id,
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
            'adults' => 1,
            'room_rate' => 45000,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('reservations', [
        'branch_id' => $this->branch->id,
        'guest_email' => 'jane@crs.com',
    ]);
});

it('shows CRS list with data', function () {
    Reservation::factory()->create([
        'branch_id' => $this->branch->id,
        'source' => 'crs',
    ]);

    $this->actingAs($this->admin)
        ->get('/crs')
        ->assertOk();
});
