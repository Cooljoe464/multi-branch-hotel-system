<?php

use App\Models\Branch;
use App\Models\MenuItem;
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

    $this->menuItem = MenuItem::factory()->food()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Grilled Chicken',
        'price' => 3500,
    ]);
});

it('loads the kitchen waste page via browser', function () {
    visit('/login')
        ->type('email', 'globaladmin@test.com')
        ->type('password', 'password')
        ->press('Log in')
        ->wait(3);

    visit('/kitchen/waste')
        ->wait(2)
        ->assertSee('Waste');
});

it('logs kitchen waste via HTTP as global admin', function () {
    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post('/kitchen/waste', [
            'menu_item_id' => $this->menuItem->id,
            'reason' => 'expired',
            'quantity' => 5,
            'cost' => 17500,
            'notes' => 'Expired ingredients',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('kitchen_waste_logs', [
        'branch_id' => $this->branch->id,
        'menu_item_id' => $this->menuItem->id,
        'reason' => 'expired',
        'quantity' => 5,
        'cost' => 17500,
        'logged_by' => $this->admin->id,
    ]);
});

it('logs kitchen waste for burned items', function () {
    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post('/kitchen/waste', [
            'menu_item_id' => $this->menuItem->id,
            'reason' => 'burned',
            'quantity' => 2,
            'cost' => 7000,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('kitchen_waste_logs', [
        'branch_id' => $this->branch->id,
        'reason' => 'burned',
        'quantity' => 2,
    ]);
});

it('logs kitchen waste for returned items', function () {
    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post('/kitchen/waste', [
            'menu_item_id' => $this->menuItem->id,
            'reason' => 'returned',
            'quantity' => 1,
            'cost' => 3500,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('kitchen_waste_logs', [
        'branch_id' => $this->branch->id,
        'reason' => 'returned',
    ]);
});

it('logs kitchen waste for overproduced items', function () {
    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post('/kitchen/waste', [
            'menu_item_id' => $this->menuItem->id,
            'reason' => 'overproduced',
            'quantity' => 10,
            'cost' => 35000,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('kitchen_waste_logs', [
        'branch_id' => $this->branch->id,
        'reason' => 'overproduced',
    ]);
});

it('shows kitchen waste report via HTTP', function () {
    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->get('/kitchen/waste/report')
        ->assertOk();
});
