<?php

use App\Models\Branch;
use App\Support\BranchTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('returns tomorrow as today for Lagos when UTC is still yesterday', function () {
    $branch = Branch::factory()->create(['timezone' => 'Africa/Lagos']);

    // 23:30 UTC is 00:30 the next day in Lagos.
    $this->travelTo(Carbon::parse('2026-09-23 23:30:00', 'UTC'));

    expect(BranchTime::today($branch))->toBe('2026-09-24');
});

it('falls back to Lagos when the branch timezone is empty', function () {
    $branch = Branch::factory()->make(['timezone' => '']);

    expect(BranchTime::timezone($branch))->toBe('Africa/Lagos');
});

it('parses dates at branch midnight', function () {
    $branch = Branch::factory()->create(['timezone' => 'Africa/Lagos']);

    $parsed = BranchTime::parse($branch, '2026-09-23');

    expect($parsed->toDateString())->toBe('2026-09-23')
        ->and($parsed->timezoneName)->toBe('Africa/Lagos')
        ->and($parsed->hour)->toBe(0);
});
