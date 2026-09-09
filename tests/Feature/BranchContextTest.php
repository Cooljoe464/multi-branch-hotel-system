<?php

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Reset permission cache
    app()->make(Permission::class)->forgetCachedPermissions();
});

test('unauthenticated user is redirected to login', function () {
    $branch = Branch::factory()->create();

    $response = $this->post(route('branch.switch'), ['branch_id' => $branch->id]);

    $response->assertRedirect(route('login'));
});

test('user can switch to accessible branch', function () {
    $branch = Branch::factory()->create();
    $user = User::factory()->create();
    $user->branches()->attach($branch);

    $this->actingAs($user);

    $response = $this->post(route('branch.switch'), ['branch_id' => $branch->id]);

    $response->assertRedirect();
    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'branch_id' => $branch->id,
    ]);
});

test('user cannot switch to inaccessible branch', function () {
    $branch = Branch::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = $this->post(route('branch.switch'), ['branch_id' => $branch->id]);

    $response->assertForbidden();
});

test('global admin can switch to any active branch', function () {
    $branch = Branch::factory()->create();
    $user = User::factory()->create(['is_global_admin' => true]);

    $this->actingAs($user);

    $response = $this->post(route('branch.switch'), ['branch_id' => $branch->id]);

    $response->assertRedirect();
    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'branch_id' => $branch->id,
    ]);
});

test('user cannot switch to inactive branch', function () {
    $branch = Branch::factory()->inactive()->create();
    $user = User::factory()->create(['is_global_admin' => true]);

    $this->actingAs($user);

    $response = $this->post(route('branch.switch'), ['branch_id' => $branch->id]);

    $response->assertSessionHasErrors('branch_id');
});

test('branch validation requires branch_id', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = $this->post(route('branch.switch'), []);

    $response->assertSessionHasErrors('branch_id');
});

test('branch validation requires existing branch', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = $this->post(route('branch.switch'), ['branch_id' => 99999]);

    $response->assertSessionHasErrors('branch_id');
});
