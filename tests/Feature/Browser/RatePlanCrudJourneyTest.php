<?php

use App\Models\Branch;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;

uses(DatabaseMigrations::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->branch = Branch::factory()->create();
    $this->roomType = RoomType::factory()->create(['branch_id' => $this->branch->id]);

    $this->admin = User::factory()->create([
        'branch_id' => $this->branch->id,
        'email' => 'globaladmin@test.com',
        'password' => 'password',
        'is_global_admin' => true,
    ]);
    $this->admin->assignRole('Global Admin');
    $this->admin->branches()->syncWithoutDetaching([$this->branch->id]);

    $this->gm = User::factory()->create([
        'branch_id' => $this->branch->id,
        'email' => 'branchgm@test.com',
        'password' => 'password',
    ]);
    $this->gm->assignRole('Branch GM');
    $this->gm->branches()->syncWithoutDetaching([$this->branch->id]);
});

it('loads the rate plans page via browser', function () {
    visit('/login')
        ->type('email', 'globaladmin@test.com')
        ->type('password', 'password')
        ->press('Log in')
        ->wait(3);

    visit('/rate-plans')
        ->wait(2)
        ->assertSee('Rate Plans')
        ->assertSee('Add Rate Plan');
});

it('creates a rate plan via HTTP as global admin', function () {
    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post('/rate-plans', [
            'name' => 'Corporate Rate',
            'code' => 'CORP',
            'type' => 'corporate',
            'room_type_id' => $this->roomType->id,
            'rate_multiplier' => 0.85,
            'is_negotiable' => false,
            'min_rate' => 10000,
            'max_rate' => 50000,
            'valid_from' => now()->toDateString(),
            'valid_to' => now()->addYear()->toDateString(),
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('rate_plans', [
        'branch_id' => $this->branch->id,
        'name' => 'Corporate Rate',
        'code' => 'CORP',
        'type' => 'corporate',
        'rate_multiplier' => 0.85,
    ]);
});

it('updates a rate plan via HTTP as global admin', function () {
    $ratePlan = RatePlan::factory()->bar()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Old Plan',
        'rate_multiplier' => 1.0,
    ]);

    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->put("/rate-plans/{$ratePlan->id}", [
            'name' => 'Updated Plan',
            'rate_multiplier' => 1.25,
            'is_active' => true,
            'min_rate' => 15000,
            'max_rate' => 80000,
            'valid_to' => now()->addMonths(6)->toDateString(),
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('rate_plans', [
        'id' => $ratePlan->id,
        'name' => 'Updated Plan',
        'rate_multiplier' => 1.25,
    ]);
});

it('creates a rate plan via HTTP as branch gm', function () {
    $this->actingAs($this->gm)
        ->withSession(['branch_id' => $this->branch->id])
        ->post('/rate-plans', [
            'name' => 'Promo Rate',
            'code' => 'PROMO',
            'type' => 'promotional',
            'room_type_id' => $this->roomType->id,
            'rate_multiplier' => 0.75,
            'is_negotiable' => false,
            'valid_from' => now()->toDateString(),
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('rate_plans', [
        'branch_id' => $this->branch->id,
        'name' => 'Promo Rate',
        'code' => 'PROMO',
        'type' => 'promotional',
    ]);
});

it('shows rate plans list with data', function () {
    RatePlan::factory()->bar()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Listed Plan',
    ]);

    $this->actingAs($this->admin)
        ->get('/rate-plans')
        ->assertOk();
});
