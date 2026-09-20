<?php

use App\Models\Branch;
use App\Models\RateOverride;
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

it('loads the rate overrides page via browser', function () {
    visit('/login')
        ->type('email', 'globaladmin@test.com')
        ->type('password', 'password')
        ->press('Log in')
        ->wait(3);

    visit('/rate-overrides')
        ->wait(2)
        ->assertSee('Rate Overrides')
        ->assertSee('New Override');
});

it('creates a rate override via HTTP as global admin', function () {
    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post('/rate-overrides', [
            'room_type_id' => $this->roomType->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
            'rate_override' => 50000,
            'mlos' => 3,
            'cta' => true,
            'ctd' => false,
            'notes' => 'Holiday season override',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('rate_overrides', [
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'rate_override' => 50000,
        'mlos' => 3,
        'cta' => true,
        'ctd' => false,
        'notes' => 'Holiday season override',
    ]);
});

it('updates a rate override via HTTP as global admin', function () {
    $override = RateOverride::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'start_date' => now()->toDateString(),
        'end_date' => now()->addWeek()->toDateString(),
        'rate_override' => 40000,
    ]);

    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->put("/rate-overrides/{$override->id}", [
            'rate_override' => 55000,
            'mlos' => 2,
            'cta' => false,
            'ctd' => true,
            'is_active' => true,
            'notes' => 'Updated override',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('rate_overrides', [
        'id' => $override->id,
        'rate_override' => 55000,
        'mlos' => 2,
        'cta' => false,
        'ctd' => true,
        'is_active' => true,
        'notes' => 'Updated override',
    ]);
});

it('creates a rate override via HTTP as branch gm', function () {
    $this->actingAs($this->gm)
        ->withSession(['branch_id' => $this->branch->id])
        ->post('/rate-overrides', [
            'room_type_id' => null,
            'start_date' => now()->addMonth()->toDateString(),
            'end_date' => now()->addMonth()->addWeek()->toDateString(),
            'rate_override' => 35000,
            'mlos' => 1,
            'cta' => false,
            'ctd' => false,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('rate_overrides', [
        'branch_id' => $this->branch->id,
        'rate_override' => 35000,
        'mlos' => 1,
    ]);
});

it('shows rate overrides list with data', function () {
    RateOverride::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'start_date' => now()->toDateString(),
        'end_date' => now()->addWeek()->toDateString(),
    ]);

    $this->actingAs($this->admin)
        ->get('/rate-overrides')
        ->assertOk();
});
