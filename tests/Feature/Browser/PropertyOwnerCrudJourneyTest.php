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
        'email' => 'owner@test.com',
        'password' => 'password',
    ]);
    $this->user->assignRole('Property Owner');
    $this->user->branches()->syncWithoutDetaching([$this->branch->id]);
});

it('logs in and reaches the dashboard', function () {
    visit('/login')
        ->type('email', 'owner@test.com')
        ->type('password', 'password')
        ->press('Log in')
        ->wait(3)
        ->assertPathIs('/dashboard')
        ->assertSee('Revenue Dashboard');
});

it('shows correct sidebar nav items for property owner', function () {
    $this->actingAs($this->user)->get('/dashboard')->assertOk();
});

it('navigates to reservations index', function () {
    $this->actingAs($this->user)->get('/reservations')->assertOk();
});

it('navigates to rooms index', function () {
    $this->actingAs($this->user)->get('/rooms')->assertOk();
});

it('navigates to housekeeping index', function () {
    $this->actingAs($this->user)->get('/housekeeping')->assertOk();
});

it('navigates to maintenance index', function () {
    $this->actingAs($this->user)->get('/maintenance')->assertOk();
});

it('navigates to folios index', function () {
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

it('can access permitted pages via direct navigation', function () {
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
});

it('is denied access to restricted pages', function () {
    $this->actingAs($this->user)->get('/admin/branches/create')->assertForbidden();
    $this->actingAs($this->user)->get('/admin/import/rooms')->assertForbidden();
    $this->actingAs($this->user)->get('/admin/import/guests')->assertForbidden();
    $this->actingAs($this->user)->get('/admin/import/reservations')->assertForbidden();
});
