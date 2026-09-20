<?php

use App\Models\Branch;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;

uses(DatabaseMigrations::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->branch = Branch::factory()->create();
    $this->user = User::factory()->create([
        'branch_id' => $this->branch->id,
        'email' => 'admin@test.com',
        'password' => 'password',
        'is_global_admin' => true,
    ]);
    $this->user->assignRole('Global Admin');
    $this->user->branches()->syncWithoutDetaching([$this->branch->id]);
});

it('navigates to branch creation wizard from sidebar', function () {
    visit('/login')
        ->type('email', 'admin@test.com')
        ->type('password', 'password')
        ->press('Log in')
        ->wait(3)
        ->assertPathIs('/dashboard')
        ->click('New Branch')
        ->wait(3)
        ->assertSee('New Branch');
});

it('shows step 1 branch info form', function () {
    visit('/login')
        ->type('email', 'admin@test.com')
        ->type('password', 'password')
        ->press('Log in')
        ->wait(3)
        ->click('New Branch')
        ->wait(3)
        ->assertSee('Branch Name')
        ->assertSee('Branch Code');
});

it('shows wizard step navigation', function () {
    visit('/login')
        ->type('email', 'admin@test.com')
        ->type('password', 'password')
        ->press('Log in')
        ->wait(3)
        ->click('New Branch')
        ->wait(3)
        ->assertSee('Next')
        ->assertSee('Room Types');
});

it('shows import nav items in sidebar after login', function () {
    visit('/login')
        ->type('email', 'admin@test.com')
        ->type('password', 'password')
        ->press('Log in')
        ->wait(3)
        ->assertSee('Import Rooms')
        ->assertSee('Import Guests')
        ->assertSee('Import Reservations');
});
