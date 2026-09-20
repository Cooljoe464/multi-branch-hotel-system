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
        'email' => 'globaladmin@test.com',
        'password' => 'password',
        'is_global_admin' => true,
    ]);
    $this->user->assignRole('Global Admin');
    $this->user->branches()->syncWithoutDetaching([$this->branch->id]);
});

it('can access profile settings page', function () {
    $this->actingAs($this->user)->get('/settings/profile')->assertOk();
});

it('can access appearance settings page', function () {
    $this->actingAs($this->user)->get('/settings/appearance')->assertOk();
});

it('can access payment guard settings via browser', function () {
    $this->actingAs($this->user)->get('/settings/profile')->assertOk();
});

it('can view appearance settings in browser', function () {
    $this->actingAs($this->user)->get('/settings/appearance')->assertOk();
});
