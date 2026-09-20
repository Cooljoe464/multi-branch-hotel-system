<?php

use App\Models\Branch;
use App\Models\InventoryItem;
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

it('loads the inventory page via browser', function () {
    visit('/login')
        ->type('email', 'globaladmin@test.com')
        ->type('password', 'password')
        ->press('Log in')
        ->wait(3);

    visit('/inventory')
        ->wait(2)
        ->assertSee('Inventory')
        ->assertSee('Add Item');
});

it('creates an inventory item via HTTP as global admin', function () {
    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post('/inventory', [
            'name' => 'Bath Towels',
            'category' => 'linen',
            'unit' => 'piece',
            'current_quantity' => 200,
            'reorder_point' => 50,
            'cost_per_unit' => 1500,
            'supplier' => 'Linen Suppliers Ltd',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('inventory_items', [
        'branch_id' => $this->branch->id,
        'name' => 'Bath Towels',
        'category' => 'linen',
        'unit' => 'piece',
        'current_quantity' => 200,
        'reorder_point' => 50,
        'cost_per_unit' => 1500,
    ]);
});

it('updates an inventory item via HTTP as global admin', function () {
    $item = InventoryItem::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Old Item',
        'reorder_point' => 10,
        'cost_per_unit' => 500,
    ]);

    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->put("/inventory/{$item->id}", [
            'name' => 'Updated Item',
            'reorder_point' => 20,
            'cost_per_unit' => 750,
            'supplier' => 'New Supplier',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('inventory_items', [
        'id' => $item->id,
        'name' => 'Updated Item',
        'reorder_point' => 20,
        'cost_per_unit' => 750,
        'supplier' => 'New Supplier',
    ]);
});

it('restocks an inventory item via HTTP as global admin', function () {
    $item = InventoryItem::factory()->create([
        'branch_id' => $this->branch->id,
        'current_quantity' => 50,
    ]);

    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post("/inventory/{$item->id}/restock", [
            'quantity' => 100,
            'notes' => 'Monthly restock',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('inventory_transactions', [
        'inventory_item_id' => $item->id,
        'type' => 'restock',
        'quantity' => 100,
        'notes' => 'Monthly restock',
    ]);
});

it('creates an inventory item via HTTP as branch gm', function () {
    $this->actingAs($this->gm)
        ->withSession(['branch_id' => $this->branch->id])
        ->post('/inventory', [
            'name' => 'Cleaning Supplies',
            'category' => 'amenity',
            'unit' => 'piece',
            'current_quantity' => 100,
            'reorder_point' => 20,
            'cost_per_unit' => 300,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('inventory_items', [
        'branch_id' => $this->branch->id,
        'name' => 'Cleaning Supplies',
        'category' => 'amenity',
    ]);
});

it('shows inventory list with data', function () {
    InventoryItem::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Listed Item',
    ]);

    $this->actingAs($this->admin)
        ->get('/inventory')
        ->assertOk();
});
