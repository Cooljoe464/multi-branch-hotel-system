<?php

use App\Services\RadiusService;

it('AC-053 combines RADIUS octet and gigaword counters into cumulative bytes', function () {
    expect(RadiusService::accountingBytes(123, 2))->toBe(8_589_934_715);
});

it('AC-053 clamps invalid and overflowing RADIUS byte counters safely', function () {
    expect(RadiusService::accountingBytes(-1, -1))->toBe(0)
        ->and(RadiusService::accountingBytes('not-a-counter', null))->toBe(0)
        ->and(RadiusService::accountingBytes(PHP_INT_MAX, 1))->toBe(PHP_INT_MAX);
});
