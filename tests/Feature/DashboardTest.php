<?php

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $branch = Branch::factory()->create();
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $user->branches()->attach($branch);
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('platform owners without a branch are redirected to platform subscriptions', function () {
    $user = User::factory()->create([
        'branch_id' => null,
        'is_global_admin' => true,
    ]);
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertRedirect(route('platform.subscriptions.index'));
});

test('users without a branch still get a 403 on the dashboard', function () {
    $user = User::factory()->create([
        'branch_id' => null,
        'is_global_admin' => false,
    ]);
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertForbidden();
});
