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

    $this->gm = User::factory()->create([
        'branch_id' => $this->branch->id,
        'email' => 'branchgm@test.com',
        'password' => 'password',
    ]);
    $this->gm->assignRole('Branch GM');
    $this->gm->branches()->syncWithoutDetaching([$this->branch->id]);
});

it('loads the menu items page via browser', function () {
    visit('/login')
        ->type('email', 'globaladmin@test.com')
        ->type('password', 'password')
        ->press('Log in')
        ->wait(3);

    visit('/menu-items')
        ->wait(2)
        ->assertSee('Menu Items')
        ->assertSee('Add Menu Item');
});

it('creates a menu item via HTTP as global admin', function () {
    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post('/menu-items', [
            'category' => 'food',
            'name' => 'Grilled Chicken',
            'price' => 3500,
            'sort_order' => 1,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('menu_items', [
        'branch_id' => $this->branch->id,
        'category' => 'food',
        'name' => 'Grilled Chicken',
        'price' => 3500,
    ]);
});

it('updates a menu item via HTTP as global admin', function () {
    $item = MenuItem::factory()->food()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Old Dish',
        'price' => 2000,
    ]);

    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->put("/menu-items/{$item->id}", [
            'name' => 'Updated Dish',
            'price' => 2500,
            'is_available' => true,
            'is_active' => true,
            'sort_order' => 2,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('menu_items', [
        'id' => $item->id,
        'name' => 'Updated Dish',
        'price' => 2500,
        'is_available' => true,
        'is_active' => true,
    ]);
});

it('creates a menu item via HTTP as branch gm', function () {
    $this->actingAs($this->gm)
        ->withSession(['branch_id' => $this->branch->id])
        ->post('/menu-items', [
            'category' => 'drink',
            'name' => 'Fresh Juice',
            'price' => 1200,
            'sort_order' => 0,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('menu_items', [
        'branch_id' => $this->branch->id,
        'category' => 'drink',
        'name' => 'Fresh Juice',
        'price' => 1200,
    ]);
});

it('shows menu items list with data', function () {
    MenuItem::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Listed Item',
        'price' => 800,
    ]);

    $this->actingAs($this->admin)
        ->get('/menu-items')
        ->assertOk();
});
