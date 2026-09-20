<?php

use App\Models\Branch;
use App\Models\LaundryOrder;
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

    $this->laundry = User::factory()->create([
        'branch_id' => $this->branch->id,
        'email' => 'laundry@test.com',
        'password' => 'password',
    ]);
    $this->laundry->assignRole('Laundry Attendant');
    $this->laundry->branches()->syncWithoutDetaching([$this->branch->id]);
});

it('loads the laundry page via browser', function () {
    visit('/login')
        ->type('email', 'globaladmin@test.com')
        ->type('password', 'password')
        ->press('Log in')
        ->wait(3);

    visit('/laundry')
        ->wait(2)
        ->assertSee('Laundry');
});

it('picks up a laundry order via HTTP as global admin', function () {
    $order = LaundryOrder::factory()->pending()->create([
        'branch_id' => $this->branch->id,
    ]);

    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post("/laundry/{$order->id}/pickup")
        ->assertRedirect();

    $this->assertDatabaseHas('laundry_orders', [
        'id' => $order->id,
        'status' => 'picked_up',
        'attendant_id' => $this->admin->id,
    ]);
});

it('delivers a laundry order via HTTP as global admin', function () {
    $order = LaundryOrder::factory()->pickedUp()->create([
        'branch_id' => $this->branch->id,
    ]);

    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post("/laundry/{$order->id}/deliver")
        ->assertRedirect();

    $this->assertDatabaseHas('laundry_orders', [
        'id' => $order->id,
        'status' => 'delivered',
    ]);
});

it('verifies a laundry order via HTTP as global admin', function () {
    $order = LaundryOrder::factory()->delivered()->create([
        'branch_id' => $this->branch->id,
    ]);

    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post("/laundry/{$order->id}/verify", [
            'items' => [
                ['type' => 'shirt', 'count' => 2],
                ['type' => 'pants', 'count' => 1],
            ],
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('laundry_orders', [
        'id' => $order->id,
        'status' => 'delivered',
    ]);
});

it('picks up a laundry order via HTTP as laundry attendant', function () {
    $order = LaundryOrder::factory()->pending()->create([
        'branch_id' => $this->branch->id,
    ]);

    $this->actingAs($this->laundry)
        ->withSession(['branch_id' => $this->branch->id])
        ->post("/laundry/{$order->id}/pickup")
        ->assertRedirect();

    $this->assertDatabaseHas('laundry_orders', [
        'id' => $order->id,
        'status' => 'picked_up',
    ]);
});

it('shows laundry list with data', function () {
    LaundryOrder::factory()->pending()->create([
        'branch_id' => $this->branch->id,
    ]);

    $this->actingAs($this->admin)
        ->get('/laundry')
        ->assertOk();
});
