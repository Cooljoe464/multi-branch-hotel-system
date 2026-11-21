<?php

use App\Models\Branch;
use App\Models\Generator;
use App\Models\GeneratorRun;
use App\Models\User;
use App\Models\UtilityMeter;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RoleSeeder::class);
    $this->branch = Branch::factory()->create();
    $this->user = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->user->branches()->attach($this->branch);
    $this->user->givePermissionTo('maintenance.view');
    $this->user->givePermissionTo('maintenance.manage');
});

it('renders the generators facilities page', function () {
    Generator::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->user)
        ->get(route('facilities.generators.index', $this->branch))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('facilities/Generators')
            ->has('generators', 1)
        );
});

it('starts a generator via controller', function () {
    $gen = Generator::factory()->create([
        'branch_id' => $this->branch->id,
        'current_meter_hours' => 100.0,
        'status' => 'idle',
    ]);

    $this->actingAs($this->user)
        ->post(route('facilities.generators.start', [$this->branch, $gen]), [
            'reason' => 'Grid Failure',
        ])
        ->assertRedirect();

    expect($gen->fresh()->status)->toBe('running');
    expect(GeneratorRun::where('generator_id', $gen->id)->count())->toBe(1);
});

it('stops a generator run via controller', function () {
    $gen = Generator::factory()->create([
        'branch_id' => $this->branch->id,
        'current_meter_hours' => 100.0,
        'status' => 'running',
    ]);

    $run = GeneratorRun::factory()->create([
        'generator_id' => $gen->id,
        'started_at' => now()->subHours(2),
        'start_meter_hours' => 100.0,
        'stopped_at' => null,
        'end_meter_hours' => null,
        'operator_id' => $this->user->id,
    ]);

    $this->actingAs($this->user)
        ->post(route('facilities.generators.stop', [$this->branch, $run]), [
            'end_meter_hours' => 102.5,
            'fuel_consumed_liters' => 25.0,
        ])
        ->assertRedirect();

    expect($gen->fresh()->status)->toBe('idle')
        ->and($gen->fresh()->current_meter_hours)->toBe(102.5)
        ->and($run->fresh()->run_hours)->toBe(2.5);
});

it('logs fuel delivery via controller', function () {
    $gen = Generator::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->user)
        ->post(route('facilities.fuel.log', $this->branch), [
            'generator_id' => $gen->id,
            'log_type' => 'delivery',
            'liters' => 500,
            'cost_per_liter_minor' => 1100,
            'supplier_name' => 'Mobil Oil',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('fuel_logs', [
        'branch_id' => $this->branch->id,
        'generator_id' => $gen->id,
        'liters' => 500,
        'cost_per_liter_minor' => 1100,
        'total_cost_minor' => 550000,
    ]);
});

it('updates utility meter reading', function () {
    $meter = UtilityMeter::factory()->create([
        'branch_id' => $this->branch->id,
        'current_reading' => 1500.0,
    ]);

    $this->actingAs($this->user)
        ->post(route('facilities.meters.update', [$this->branch, $meter]), [
            'current_reading' => 1650.0,
        ])
        ->assertRedirect();

    expect($meter->fresh()->current_reading)->toBe(1650.0);
});
