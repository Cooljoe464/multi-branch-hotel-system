<?php

use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Services\NullPbxDriver;
use App\Services\PbxDriverManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('resolves the vendor-less placeholder driver by default', function () {
    $branch = Branch::factory()->create();

    config(['services.pbx.driver' => null]);

    $driver = app(PbxDriverManager::class)->driver($branch);

    expect($driver)->toBeInstanceOf(NullPbxDriver::class)
        ->and($driver->name())->toBe('null')
        ->and($driver->poll($branch, Carbon::now()->subDay()))->toBe([]);
});

it('reports webhook readiness from the branch cdr secret', function () {
    $branch = Branch::factory()->create(['cdr_secret' => '']);
    $driver = app(PbxDriverManager::class)->driver($branch);

    expect($driver->isConfigured($branch))->toBeFalse();

    $branch->update(['cdr_secret' => 'shared-hmac-secret']);

    expect($driver->isConfigured($branch->fresh()))->toBeTrue();
});

it('refuses an unknown driver instead of billing with the wrong vendor', function () {
    config(['services.pbx.driver' => 'acme-pbx']);

    try {
        app(PbxDriverManager::class)->driver(Branch::factory()->create());
        $this->fail('Expected an PBX_UNCONFIGURED exception.');
    } catch (AvailabilityException $e) {
        expect($e->availabilityCode)->toBe('PBX_UNCONFIGURED')
            ->and($e->getMessage())->toContain('acme-pbx');
    }
});
