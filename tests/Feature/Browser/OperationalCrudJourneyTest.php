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
        'status' => 'available',
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

it('can view housekeeping page', function () {
    $this->actingAs($this->user)->get('/housekeeping')->assertOk();
});

it('can view laundry page', function () {
    $this->actingAs($this->user)->get('/laundry')->assertOk();
});

it('can view KDS page', function () {
    $this->actingAs($this->user)->get('/kds')->assertOk();
});

it('can view maintenance page', function () {
    $this->actingAs($this->user)->get('/maintenance')->assertOk();
});

it('can view import guests page', function () {
    $this->actingAs($this->user)->get('/admin/import/guests')->assertOk();
});

it('can view dashboard with stats', function () {
    $this->actingAs($this->user)->get('/dashboard')->assertOk();
});

it('can view reports page', function () {
    $this->actingAs($this->user)->get('/reports')->assertOk();
});
