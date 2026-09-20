<?php

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

it('loads the yield rules page via browser', function () {
    visit('/login')
        ->type('email', 'globaladmin@test.com')
        ->type('password', 'password')
        ->press('Log in')
        ->wait(3);

    visit('/yield-rules')
        ->wait(2)
        ->assertSee('Yield Rules')
        ->assertSee('New Rule');
});

it('creates a yield rule via HTTP as global admin', function () {
    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post('/yield-rules', [
            'room_type_id' => $this->roomType->id,
            'min_occupancy_pct' => 50,
            'max_occupancy_pct' => 70,
            'rate_multiplier' => 1.15,
            'mlos_override' => 2,
            'cta_override' => false,
            'priority' => 1,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('yield_rules', [
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'min_occupancy_pct' => 50,
        'max_occupancy_pct' => 70,
        'rate_multiplier' => 1.15,
        'priority' => 1,
    ]);
});

it('updates a yield rule via HTTP as global admin', function () {
    $rule = YieldRule::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'min_occupancy_pct' => 30,
        'max_occupancy_pct' => 50,
        'rate_multiplier' => 1.0,
    ]);

    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->put("/yield-rules/{$rule->id}", [
            'min_occupancy_pct' => 60,
            'max_occupancy_pct' => 80,
            'rate_multiplier' => 1.25,
            'is_active' => true,
            'priority' => 2,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('yield_rules', [
        'id' => $rule->id,
        'min_occupancy_pct' => 60,
        'max_occupancy_pct' => 80,
        'rate_multiplier' => 1.25,
        'is_active' => true,
        'priority' => 2,
    ]);
});

it('creates a yield rule via HTTP as branch gm', function () {
    $this->actingAs($this->gm)
        ->withSession(['branch_id' => $this->branch->id])
        ->post('/yield-rules', [
            'room_type_id' => null,
            'min_occupancy_pct' => 80,
            'max_occupancy_pct' => 100,
            'rate_multiplier' => 1.5,
            'priority' => 5,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('yield_rules', [
        'branch_id' => $this->branch->id,
        'min_occupancy_pct' => 80,
        'max_occupancy_pct' => 100,
        'rate_multiplier' => 1.5,
    ]);
});

it('shows yield rules list with data', function () {
    YieldRule::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
    ]);

    $this->actingAs($this->admin)
        ->get('/yield-rules')
        ->assertOk();
});
