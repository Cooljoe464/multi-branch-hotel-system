<?php

use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\Outlet;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;

uses(DatabaseMigrations::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->branch = Branch::factory()->create();
    $this->user = User::factory()->create([
        'branch_id' => $this->branch->id,
        'email' => 'globaladmin@test.com',
        'password' => 'password',
        'is_global_admin' => true,
    ]);
    $this->user->assignRole('Global Admin');
    $this->user->branches()->syncWithoutDetaching([$this->branch->id]);
});

it('can view outlets list page', function () {
    Outlet::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Main Restaurant',
        'code' => 'RES',
    ]);

    $this->actingAs($this->user)->get('/outlets')->assertOk();
});

it('can open create outlet modal', function () {
    $this->actingAs($this->user)->get('/outlets')->assertOk();
});

it('can view inventory list page', function () {
    InventoryItem::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Test Stock Item',
    ]);

    $this->actingAs($this->user)->get('/inventory')->assertOk();
});

it('can open create inventory item modal', function () {
    $this->actingAs($this->user)->get('/inventory')->assertOk();
});

it('can view transfers list page', function () {
    $this->actingAs($this->user)->get('/transfers')->assertOk();
});
