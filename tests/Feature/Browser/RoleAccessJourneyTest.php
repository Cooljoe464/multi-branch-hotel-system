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

it('allows front desk to access reservations', function () {
    $user = User::factory()->create([
        'branch_id' => $this->branch->id,
        'email' => 'frontdesk-access@test.com',
        'password' => 'password',
    ]);
    $user->assignRole('Front Desk');
    $user->branches()->syncWithoutDetaching([$this->branch->id]);

    $this->actingAs($user)->get('/reservations')->assertOk();
});

it('denies housekeeper access to analytics page', function () {
    $user = User::factory()->create([
        'branch_id' => $this->branch->id,
        'email' => 'housekeeper-analytics@test.com',
        'password' => 'password',
    ]);
    $user->assignRole('Housekeeper');
    $user->branches()->syncWithoutDetaching([$this->branch->id]);

    $this->actingAs($user)->get('/analytics')->assertForbidden();
});

it('denies housekeeper access to reports page', function () {
    $user = User::factory()->create([
        'branch_id' => $this->branch->id,
        'email' => 'housekeeper-reports@test.com',
        'password' => 'password',
    ]);
    $user->assignRole('Housekeeper');
    $user->branches()->syncWithoutDetaching([$this->branch->id]);

    $this->actingAs($user)->get('/reports')->assertForbidden();
});

it('denies housekeeper access to yield rules page', function () {
    $user = User::factory()->create([
        'branch_id' => $this->branch->id,
        'email' => 'housekeeper-yield@test.com',
        'password' => 'password',
    ]);
    $user->assignRole('Housekeeper');
    $user->branches()->syncWithoutDetaching([$this->branch->id]);

    $this->actingAs($user)->get('/yield-rules')->assertForbidden();
});

it('denies front desk access to analytics page', function () {
    $user = User::factory()->create([
        'branch_id' => $this->branch->id,
        'email' => 'frontdesk-no-analytics@test.com',
        'password' => 'password',
    ]);
    $user->assignRole('Front Desk');
    $user->branches()->syncWithoutDetaching([$this->branch->id]);

    $this->actingAs($user)->get('/analytics')->assertForbidden();
});

it('allows global admin to access all pages', function () {
    $user = User::factory()->create([
        'branch_id' => $this->branch->id,
        'email' => 'globaladmin@test.com',
        'password' => 'password',
        'is_global_admin' => true,
    ]);
    $user->assignRole('Global Admin');
    $user->branches()->syncWithoutDetaching([$this->branch->id]);

    $this->actingAs($user)->get('/analytics')->assertOk();
    $this->actingAs($user)->get('/reports')->assertOk();
    $this->actingAs($user)->get('/yield-rules')->assertOk();
    $this->actingAs($user)->get('/reservations')->assertOk();
    $this->actingAs($user)->get('/rooms')->assertOk();
    $this->actingAs($user)->get('/housekeeping')->assertOk();
    $this->actingAs($user)->get('/maintenance')->assertOk();
    $this->actingAs($user)->get('/folios')->assertOk();
    $this->actingAs($user)->get('/tape-chart')->assertOk();
});

it('denies unauthenticated access to staff pages', function () {
    $this->get('/dashboard')->assertRedirect('/login');
    $this->get('/reservations')->assertRedirect('/login');
    $this->get('/rooms')->assertRedirect('/login');
    $this->get('/analytics')->assertRedirect('/login');
    $this->get('/reports')->assertRedirect('/login');
    $this->get('/housekeeping')->assertRedirect('/login');
    $this->get('/maintenance')->assertRedirect('/login');
    $this->get('/folios')->assertRedirect('/login');
    $this->get('/tape-chart')->assertRedirect('/login');
});
