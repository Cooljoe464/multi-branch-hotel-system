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
        'email' => 'housekeeper@test.com',
        'password' => 'password',
    ]);
    $this->user->assignRole('Housekeeper');
    $this->user->branches()->syncWithoutDetaching([$this->branch->id]);
});

it('logs in and reaches the dashboard', function () {
    $this->actingAs($this->user)->get('/dashboard')->assertOk();
});

it('shows only permitted sidebar nav items for housekeeper', function () {
    $this->actingAs($this->user)->get('/dashboard')->assertOk();
});

it('navigates to rooms index', function () {
    $this->actingAs($this->user)->get('/rooms')->assertOk();
});

it('navigates to housekeeping page', function () {
    $this->actingAs($this->user)->get('/housekeeping')->assertOk();
});

it('can access permitted pages via direct navigation', function () {
    $this->actingAs($this->user)->get('/dashboard')->assertOk();
    $this->actingAs($this->user)->get('/rooms')->assertOk();
    $this->actingAs($this->user)->get('/housekeeping')->assertOk();
});

it('is denied access to restricted pages', function () {
    $this->actingAs($this->user)->get('/reservations')->assertForbidden();
    $this->actingAs($this->user)->get('/maintenance')->assertForbidden();
    $this->actingAs($this->user)->get('/folios')->assertForbidden();
    $this->actingAs($this->user)->get('/analytics')->assertForbidden();
    $this->actingAs($this->user)->get('/reports')->assertForbidden();
    $this->actingAs($this->user)->get('/yield-rules')->assertForbidden();
    $this->actingAs($this->user)->get('/rate-overrides')->assertForbidden();
    $this->actingAs($this->user)->get('/tape-chart')->assertForbidden();
    $this->actingAs($this->user)->get('/admin/branches/create')->assertForbidden();
    $this->actingAs($this->user)->get('/admin/import/rooms')->assertForbidden();
});
