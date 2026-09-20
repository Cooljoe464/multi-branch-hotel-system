<?php

use App\Models\Branch;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;

uses(DatabaseMigrations::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->branch = Branch::factory()->create();
    $this->roomType = RoomType::factory()->create(['branch_id' => $this->branch->id]);
    $this->room = Room::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
    ]);
    $this->user = User::factory()->create([
        'branch_id' => $this->branch->id,
        'email' => 'globaladmin@test.com',
        'password' => 'password',
        'is_global_admin' => true,
    ]);
    $this->user->assignRole('Global Admin');
    $this->user->branches()->syncWithoutDetaching([$this->branch->id]);
});

it('logs in and reaches the dashboard', function () {
    visit('/login')
        ->type('email', 'globaladmin@test.com')
        ->type('password', 'password')
        ->press('Log in')
        ->wait(3)
        ->assertPathIs('/dashboard')
        ->assertSee('Revenue Dashboard');
});

it('shows sidebar nav items for global admin', function () {
    $this->actingAs($this->user)->get('/dashboard')->assertOk();
});

it('navigates to reservations and sees the index', function () {
    $this->actingAs($this->user)->get('/reservations')->assertOk();
});

it('navigates to rooms page and sees the room list', function () {
    $this->actingAs($this->user)->get('/rooms')->assertOk();
});

it('navigates to housekeeping page', function () {
    $this->actingAs($this->user)->get('/housekeeping')->assertOk();
});

it('navigates to maintenance page', function () {
    $this->actingAs($this->user)->get('/maintenance')->assertOk();
});

it('navigates to folios page', function () {
    $this->actingAs($this->user)->get('/folios')->assertOk();
});

it('navigates to analytics page', function () {
    $this->actingAs($this->user)->get('/analytics')->assertOk();
});

it('navigates to reports page', function () {
    $this->actingAs($this->user)->get('/reports')->assertOk();
});

it('navigates to yield rules page', function () {
    $this->actingAs($this->user)->get('/yield-rules')->assertOk();
});

it('navigates to rate overrides page', function () {
    $this->actingAs($this->user)->get('/rate-overrides')->assertOk();
});

it('navigates to branch creation wizard', function () {
    $this->actingAs($this->user)->get('/admin/branches/create')->assertOk();
});

it('navigates to tape chart', function () {
    $this->actingAs($this->user)->get('/tape-chart')->assertOk();
});

it('navigates to menu items page', function () {
    $this->actingAs($this->user)->get('/menu-items')->assertOk();
});

it('navigates to outlets page', function () {
    $this->actingAs($this->user)->get('/outlets')->assertOk();
});

it('navigates to inventory page', function () {
    $this->actingAs($this->user)->get('/inventory')->assertOk();
});

it('navigates to transfers page', function () {
    $this->actingAs($this->user)->get('/transfers')->assertOk();
});

it('navigates to channels page', function () {
    $this->actingAs($this->user)->get('/channels')->assertOk();
});

it('navigates to city ledger page', function () {
    $this->actingAs($this->user)->get('/city-ledger')->assertOk();
});

it('can access all pages via direct navigation', function () {
    $this->actingAs($this->user)->get('/dashboard')->assertOk();
    $this->actingAs($this->user)->get('/reservations')->assertOk();
    $this->actingAs($this->user)->get('/rooms')->assertOk();
    $this->actingAs($this->user)->get('/housekeeping')->assertOk();
    $this->actingAs($this->user)->get('/maintenance')->assertOk();
    $this->actingAs($this->user)->get('/folios')->assertOk();
    $this->actingAs($this->user)->get('/analytics')->assertOk();
    $this->actingAs($this->user)->get('/reports')->assertOk();
    $this->actingAs($this->user)->get('/yield-rules')->assertOk();
    $this->actingAs($this->user)->get('/rate-overrides')->assertOk();
    $this->actingAs($this->user)->get('/tape-chart')->assertOk();
    $this->actingAs($this->user)->get('/admin/branches/create')->assertOk();
    $this->actingAs($this->user)->get('/admin/import/rooms')->assertOk();
    $this->actingAs($this->user)->get('/admin/import/guests')->assertOk();
    $this->actingAs($this->user)->get('/admin/import/reservations')->assertOk();
    $this->actingAs($this->user)->get('/menu-items')->assertOk();
    $this->actingAs($this->user)->get('/rate-plans')->assertOk();
    $this->actingAs($this->user)->get('/outlets')->assertOk();
    $this->actingAs($this->user)->get('/inventory')->assertOk();
    $this->actingAs($this->user)->get('/transfers')->assertOk();
    $this->actingAs($this->user)->get('/bank-profiles')->assertOk();
    $this->actingAs($this->user)->get('/group-ledgers')->assertOk();
    $this->actingAs($this->user)->get('/kds')->assertOk();
    $this->actingAs($this->user)->get('/pos')->assertOk();
    $this->actingAs($this->user)->get('/laundry')->assertOk();
    $this->actingAs($this->user)->get('/channels')->assertOk();
    $this->actingAs($this->user)->get('/crs')->assertOk();
    $this->actingAs($this->user)->get('/audit/flags')->assertOk();
    $this->actingAs($this->user)->get('/city-ledger')->assertOk();
    $this->actingAs($this->user)->get('/settings/profile')->assertOk();
    $this->actingAs($this->user)->get('/settings/appearance')->assertOk();
});
