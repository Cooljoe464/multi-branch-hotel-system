<?php

use App\Models\Branch;
use App\Models\Generator;
use App\Models\GeneratorRun;
use App\Models\User;
use App\Services\GeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new GeneratorService;
    $this->branch = Branch::factory()->create();
    $this->user = User::factory()->create();
});

it('starts a generator run', function () {
    $gen = Generator::factory()->create([
        'branch_id' => $this->branch->id,
        'current_meter_hours' => 500.0,
        'status' => 'idle',
    ]);

    $run = $this->service->startRun($gen, $this->user, 'power_outage');

    expect($run)->toBeInstanceOf(GeneratorRun::class)
        ->and($run->start_meter_hours)->toBe(500.0)
        ->and($run->reason)->toBe('power_outage')
        ->and($gen->fresh()->status)->toBe('running');
});

it('prevents starting already running generator', function () {
    $gen = Generator::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => 'idle',
    ]);

    $this->service->startRun($gen, $this->user, 'power_outage');

    $this->service->startRun($gen, $this->user, 'test');
})->throws(LogicException::class, 'Generator is already running.');

it('stops a generator run', function () {
    $gen = Generator::factory()->create([
        'branch_id' => $this->branch->id,
        'current_meter_hours' => 1000.0,
        'status' => 'idle',
    ]);

    $run = $this->service->startRun($gen, $this->user, 'power_outage');
    $stopped = $this->service->stopRun($run, 1005.0, 50.0);

    expect($stopped->run_hours)->toBe(5.0)
        ->and($stopped->fuel_consumed_liters)->toBe(50.0)
        ->and($gen->fresh()->status)->toBe('idle')
        ->and($gen->fresh()->current_meter_hours)->toBe(1005.0);
});

it('logs fuel delivery', function () {
    $gen = Generator::factory()->create(['branch_id' => $this->branch->id]);

    $log = $this->service->logFuel($this->branch, [
        'generator_id' => $gen->id,
        'log_type' => 'delivery',
        'liters' => 200.0,
        'cost_per_liter_minor' => 1000,
        'supplier_name' => 'Total Energies',
        'invoice_number' => 'INV-001',
    ], $this->user);

    expect($log->total_cost_minor)->toBe(200000)
        ->and($log->liters)->toBe(200.0)
        ->and($log->log_type)->toBe('delivery');
});

it('detects fuel anomaly', function () {
    $gen = Generator::factory()->create([
        'branch_id' => $this->branch->id,
        'liters_per_hour_rated' => 10.0,
    ]);

    // 15 liters for 1 hour = 50% over expected (10) → anomaly
    $isAnomaly = $this->service->detectFuelAnomaly($gen, 15.0, 1.0);

    expect($isAnomaly)->toBeTrue();

    // 10.5 liters for 1 hour = 5% over expected → not anomaly
    $isNormal = $this->service->detectFuelAnomaly($gen, 10.5, 1.0);

    expect($isNormal)->toBeFalse();
});
