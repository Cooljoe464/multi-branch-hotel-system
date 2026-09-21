<?php

use App\Models\Branch;
use Database\Seeders\DemoTenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds the deterministic demo tenant', function () {
    $started = microtime(true);

    $this->seed(DemoTenantSeeder::class);

    $counts = app(DemoTenantSeeder::class)->counts();

    expect($counts)->toBe([
        'branches' => 2,
        'room_types' => 10,
        'rooms' => 120,
        'users' => 12,
    ]);

    // Every demo branch has exactly one open business date.
    foreach (Branch::whereIn('code', ['DEMO-LOS', 'DEMO-ABV'])->get() as $branch) {
        expect($branch->businessDates()->open()->count())->toBe(1);
    }

    // Re-running changes nothing (idempotent seed).
    $this->seed(DemoTenantSeeder::class);

    expect(app(DemoTenantSeeder::class)->counts())->toBe($counts);

    $elapsed = microtime(true) - $started;
    expect($elapsed)->toBeLessThan(90);
});
