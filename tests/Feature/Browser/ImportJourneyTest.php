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

it('navigates to import rooms page from sidebar', function () {
    visit('/login')
        ->type('email', 'admin@test.com')
        ->type('password', 'password')
        ->press('Log in')
        ->wait(3)
        ->assertPathIs('/dashboard')
        ->click('Import Rooms')
        ->wait(3)
        ->assertSee('Import Rooms');
});

it('shows download template link on import rooms page', function () {
    visit('/login')
        ->type('email', 'admin@test.com')
        ->type('password', 'password')
        ->press('Log in')
        ->wait(3)
        ->click('Import Rooms')
        ->wait(3)
        ->assertSee('Download Template');
});

it('shows file upload area on import rooms page', function () {
    $this->actingAs($this->user)->get('/admin/import/rooms')->assertOk();
});

it('navigates to import guests page from sidebar', function () {
    $this->actingAs($this->user)->get('/admin/import/guests')->assertOk();
});
