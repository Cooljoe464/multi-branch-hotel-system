<?php

use App\Models\Branch;
use App\Models\Outlet;
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

it('loads the outlets page via browser', function () {
    visit('/login')
        ->type('email', 'globaladmin@test.com')
        ->type('password', 'password')
        ->press('Log in')
        ->wait(3);

    visit('/outlets')
        ->wait(2)
        ->assertSee('Outlets')
        ->assertSee('Add Outlet');
});

it('creates an outlet via HTTP as global admin', function () {
    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post('/outlets', [
            'name' => 'Main Restaurant',
            'code' => 'REST-01',
            'type' => 'restaurant',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('outlets', [
        'branch_id' => $this->branch->id,
        'name' => 'Main Restaurant',
        'code' => 'REST-01',
        'type' => 'restaurant',
    ]);
});

it('updates an outlet via HTTP as global admin', function () {
    $outlet = Outlet::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Old Name',
        'code' => 'OLD-01',
        'type' => 'bar',
    ]);

    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->put("/outlets/{$outlet->id}", [
            'name' => 'Updated Restaurant',
            'code' => 'OLD-01',
            'type' => 'bar',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('outlets', [
        'id' => $outlet->id,
        'name' => 'Updated Restaurant',
        'type' => 'bar',
    ]);
});

it('creates an outlet via HTTP as branch gm', function () {
    $this->actingAs($this->gm)
        ->withSession(['branch_id' => $this->branch->id])
        ->post('/outlets', [
            'name' => 'GM Restaurant',
            'code' => 'GM-R01',
            'type' => 'restaurant',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('outlets', [
        'branch_id' => $this->branch->id,
        'name' => 'GM Restaurant',
        'code' => 'GM-R01',
    ]);
});

it('shows outlets list with data', function () {
    Outlet::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Test Outlet',
        'code' => 'TST-01',
    ]);

    $this->actingAs($this->admin)
        ->get('/outlets')
        ->assertOk();
});
