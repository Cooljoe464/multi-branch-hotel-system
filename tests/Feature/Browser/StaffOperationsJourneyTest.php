<?php

use App\Models\Branch;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;

uses(DatabaseMigrations::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->branch = Branch::factory()->create();
});

it('loads the login page', function () {
    visit('/login')
        ->assertSee('Log in')
        ->assertPathIs('/login');
});

it('logs in as staff and reaches the dashboard', function () {
    $user = User::factory()->create([
        'branch_id' => $this->branch->id,
        'email' => 'frontdesk@test.com',
        'password' => 'password',
    ]);
    $user->assignRole('Front Desk');
    $user->branches()->syncWithoutDetaching([$this->branch->id]);

    visit('/login')
        ->type('email', 'frontdesk@test.com')
        ->type('password', 'password')
        ->press('Log in')
        ->wait(3)
        ->assertPathIs('/dashboard')
        ->assertSee('Dashboard');
});

it('shows validation errors on invalid login', function () {
    visit('/login')
        ->type('email', 'nonexistent@example.com')
        ->type('password', 'wrongpassword')
        ->press('Log in')
        ->wait(2)
        ->assertPathIs('/login');
});

it('navigates to reservations from dashboard', function () {
    $user = User::factory()->create([
        'branch_id' => $this->branch->id,
        'email' => 'gm@test.com',
        'password' => 'password',
    ]);
    $user->assignRole('Branch GM');
    $user->branches()->syncWithoutDetaching([$this->branch->id]);

    $this->actingAs($user)->get('/reservations')->assertOk();
});

it('navigates to tape chart from dashboard', function () {
    $user = User::factory()->create([
        'branch_id' => $this->branch->id,
        'email' => 'gm-tape@test.com',
        'password' => 'password',
    ]);
    $user->assignRole('Branch GM');
    $user->branches()->syncWithoutDetaching([$this->branch->id]);

    $this->actingAs($user)->get('/tape-chart')->assertOk();
});

it('logs out successfully', function () {
    $user = User::factory()->create([
        'branch_id' => $this->branch->id,
        'email' => 'logout@test.com',
        'password' => 'password',
    ]);
    $user->assignRole('Front Desk');
    $user->branches()->syncWithoutDetaching([$this->branch->id]);

    visit('/login')
        ->type('email', 'logout@test.com')
        ->type('password', 'password')
        ->press('Log in')
        ->wait(3)
        ->assertPathIs('/dashboard')
        ->click('[aria-haspopup="menu"]')
        ->wait(1)
        ->click('[data-test="logout-button"]')
        ->wait(2)
        ->assertDontSee('Dashboard')
        ->assertSee('Log in');
});
