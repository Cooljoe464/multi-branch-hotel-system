<?php

use App\Models\Branch;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()->make(Permission::class)->forgetCachedPermissions();
});

test('roles are created with correct permissions', function () {
    $this->seed(RoleSeeder::class);

    expect(Role::count())->toBe(9);
    expect(Permission::count())->toBeGreaterThan(0);

    $globalAdmin = Role::findByName('Global Admin');
    expect($globalAdmin->permissions->count())->toBe(Permission::count());

    $housekeeper = Role::findByName('Housekeeper');
    expect($housekeeper->permissions->pluck('name')->toArray())->toBe([
        'rooms.view',
        'rooms.update_status',
        'housekeeping.view',
        'housekeeping.manage',
    ]);
});

test('user can be assigned role', function () {
    $this->seed(RoleSeeder::class);

    $role = Role::findByName('Front Desk');
    $user = User::factory()->create();
    $user->assignRole($role);

    expect($user->hasRole('Front Desk'))->toBeTrue();
    expect($user->hasPermissionTo('reservations.view'))->toBeTrue();
    expect($user->hasPermissionTo('rooms.view'))->toBeTrue();
    expect($user->hasPermissionTo('users.delete'))->toBeFalse();
});

test('user can access branch after assignment', function () {
    $branch = Branch::factory()->create();
    $user = User::factory()->create();

    $user->branches()->attach($branch);

    expect($user->hasAccessToBranch($branch))->toBeTrue();
});

test('user cannot access unassigned branch', function () {
    $branch = Branch::factory()->create();
    $user = User::factory()->create();

    expect($user->hasAccessToBranch($branch))->toBeFalse();
});

test('global admin can access any branch', function () {
    $branch = Branch::factory()->create();
    $user = User::factory()->create(['is_global_admin' => true]);

    expect($user->hasAccessToBranch($branch))->toBeTrue();
});

test('user has default branch', function () {
    $branch = Branch::factory()->create();
    $user = User::factory()->create();

    $user->branches()->attach($branch, ['is_default' => true]);

    expect($user->defaultBranch()->id)->toBe($branch->id);
});

test('user branches relationship works', function () {
    $branches = Branch::factory()->count(3)->create();
    $user = User::factory()->create();

    $user->branches()->attach($branches->pluck('id')->toArray());

    expect($user->branches()->count())->toBe(3);
});
