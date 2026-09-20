<?php

use App\Models\BankProfile;
use App\Models\Branch;
use App\Models\RoomType;
use App\Models\User;
use App\Models\YieldRule;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;

uses(DatabaseMigrations::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->branch = Branch::factory()->create();
    $this->roomType = RoomType::factory()->create(['branch_id' => $this->branch->id]);
    $this->user = User::factory()->create([
        'branch_id' => $this->branch->id,
        'email' => 'globaladmin@test.com',
        'password' => 'password',
        'is_global_admin' => true,
    ]);
    $this->user->assignRole('Global Admin');
    $this->user->branches()->syncWithoutDetaching([$this->branch->id]);
});

it('can view yield rules page', function () {
    $this->actingAs($this->user)->get('/yield-rules')->assertOk();
});

it('can view yield rules list with data', function () {
    YieldRule::create([
        'branch_id' => $this->branch->id,
        'min_occupancy_pct' => 50,
        'max_occupancy_pct' => 80,
        'rate_multiplier' => 1.25,
    ]);

    $this->actingAs($this->user)->get('/yield-rules')->assertOk();
});

it('can view rate overrides page', function () {
    $this->actingAs($this->user)->get('/rate-overrides')->assertOk();
});

it('can view bank profiles page', function () {
    $this->actingAs($this->user)->get('/bank-profiles')->assertOk();
});

it('can view bank profiles with data', function () {
    BankProfile::factory()->create([
        'branch_id' => $this->branch->id,
        'bank_name' => 'First Bank',
        'account_number' => '1234567890',
        'account_name' => 'John Doe',
    ]);

    $this->actingAs($this->user)->get('/bank-profiles')->assertOk();
});
