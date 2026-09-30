<?php

use App\Models\Branch;
use App\Models\FxRate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['timezone' => 'Africa/Lagos']);
    $this->user = $this->makeAdminUser($this->branch);
});

it('saves an fx rate through the settings UI', function () {
    $this->actingAs($this->user)->post('/settings/fx-rates', [
        'base_code' => 'usd',
        'quote_code' => 'ngn',
        'rate_date' => '2026-09-29',
        'rate' => 1500.5,
    ])->assertRedirect();

    $rate = FxRate::forPair('USD', 'NGN')->firstOrFail();

    expect($rate->rate_micro)->toBe(1500500000)
        ->and($rate->rate_date->toDateString())->toBe('2026-09-29');
});

it('rejects same-currency pairs', function () {
    $this->actingAs($this->user)->post('/settings/fx-rates', [
        'base_code' => 'USD',
        'quote_code' => 'USD',
        'rate_date' => '2026-09-29',
        'rate' => 1,
    ])->assertSessionHasErrors('quote_code');
});

it('deletes a rate', function () {
    $rate = FxRate::create([
        'base_code' => 'USD',
        'quote_code' => 'NGN',
        'rate_date' => '2026-09-29',
        'rate_micro' => 1500000000,
    ]);

    $this->actingAs($this->user)->delete("/settings/fx-rates/{$rate->id}")->assertRedirect();

    expect(FxRate::where('id', $rate->id)->count())->toBe(0);
});
