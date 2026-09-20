<?php

use App\Models\Branch;
use App\Models\Outlet;
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

    $this->cashier = User::factory()->create([
        'branch_id' => $this->branch->id,
        'email' => 'cashier@test.com',
        'password' => 'password',
    ]);
    $this->cashier->assignRole('Cashier');
    $this->cashier->branches()->syncWithoutDetaching([$this->branch->id]);

    $this->roomType = RoomType::factory()->create([
        'branch_id' => $this->branch->id,
    ]);

    $this->room = Room::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'status' => 'occupied',
    ]);

    $this->reservation = Reservation::factory()->create([
        'branch_id' => $this->branch->id,
        'room_id' => $this->room->id,
        'room_type_id' => $this->roomType->id,
        'status' => 'checked_in',
    ]);

    $this->outlet = Outlet::factory()->create([
        'branch_id' => $this->branch->id,
        'type' => 'restaurant',
    ]);
});

it('loads the POS page via browser', function () {
    visit('/login')
        ->type('email', 'globaladmin@test.com')
        ->type('password', 'password')
        ->press('Log in')
        ->wait(3);

    visit('/pos')
        ->wait(2)
        ->assertSee('POS');
});

it('posts a POS charge via HTTP as global admin', function () {
    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post("/pos/{$this->outlet->id}/charge", [
            'reservation_id' => $this->reservation->id,
            'items' => [
                ['name' => 'Grilled Chicken', 'quantity' => 2, 'price' => 3500],
                ['name' => 'Fresh Juice', 'quantity' => 1, 'price' => 1200],
            ],
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('pos_charges', [
        'branch_id' => $this->branch->id,
        'reservation_id' => $this->reservation->id,
        'outlet' => $this->outlet->code,
    ]);
});

it('posts a POS charge with single item via HTTP', function () {
    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post("/pos/{$this->outlet->id}/charge", [
            'reservation_id' => $this->reservation->id,
            'items' => [
                ['name' => 'Water Bottle', 'quantity' => 1, 'price' => 500],
            ],
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('pos_charges', [
        'branch_id' => $this->branch->id,
        'reservation_id' => $this->reservation->id,
    ]);
});

it('posts a POS charge via HTTP as cashier', function () {
    $this->actingAs($this->cashier)
        ->withSession(['branch_id' => $this->branch->id])
        ->post("/pos/{$this->outlet->id}/charge", [
            'reservation_id' => $this->reservation->id,
            'items' => [
                ['name' => 'Coffee', 'quantity' => 1, 'price' => 800],
            ],
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('pos_charges', [
        'branch_id' => $this->branch->id,
    ]);
});
