<?php

use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Services\FxService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->fx = new FxService;
});

it('converts minor units with exact integer math', function () {
    $this->fx->setRate('USD', 'NGN', '2026-09-23', 1500000000);

    // $100.00 at 1500 NGN/USD lands on exactly ₦150,000.00.
    expect($this->fx->convert(10000, 'USD', 'NGN', '2026-09-23'))->toBe(15000000);
});

it('treats same-currency conversion as identity without a rate row', function () {
    expect($this->fx->convert(12345, 'NGN', 'NGN', '2026-09-23'))->toBe(12345);
});

it('falls back to the latest rate on or before the date', function () {
    $this->fx->setRate('USD', 'NGN', '2026-09-20', 1400000000);
    $this->fx->setRate('USD', 'NGN', '2026-09-22', 1500000000);

    expect($this->fx->convert(10000, 'USD', 'NGN', '2026-09-23'))->toBe(15000000)
        ->and($this->fx->convert(10000, 'USD', 'NGN', '2026-09-21'))->toBe(14000000);
});

it('rejects conversion with no rate on or before the date', function () {
    $this->fx->setRate('USD', 'NGN', '2026-09-23', 1500000000);

    try {
        $this->fx->convert(10000, 'USD', 'NGN', '2026-09-22');
        $this->fail('Expected an FX_RATE_MISSING exception.');
    } catch (AvailabilityException $e) {
        expect($e->availabilityCode)->toBe('FX_RATE_MISSING');
    }
});

it('rejects non-positive rates', function () {
    try {
        $this->fx->setRate('USD', 'NGN', '2026-09-23', 0);
        $this->fail('Expected an FX_RATE_INVALID exception.');
    } catch (AvailabilityException $e) {
        expect($e->availabilityCode)->toBe('FX_RATE_INVALID');
    }
});

it('converts from the branch currency using the branch clock', function () {
    $branch = Branch::factory()->create(['timezone' => 'Africa/Lagos', 'currency_code' => 'NGN']);
    $this->fx->setRate('NGN', 'USD', '2026-09-23', 667);

    // ₦15,000.00 at 667 micro-USD/NGN rounds half-up to $10.01.
    expect($this->fx->convertForBranch(1500000, $branch, 'USD', '2026-09-23'))->toBe(1001);
});
