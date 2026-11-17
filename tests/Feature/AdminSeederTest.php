<?php

use App\Models\User;
use Database\Seeders\AdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('admin seeder creates a platform owner with global admin role', function () {
    $this->seed(AdminSeeder::class);

    $owner = User::where('email', 'platform.owner@hotel.com')->first();

    expect($owner)->not->toBeNull()
        ->and($owner->is_global_admin)->toBeTrue()
        ->and($owner->hasRole('Global Admin'))->toBeTrue();
});
