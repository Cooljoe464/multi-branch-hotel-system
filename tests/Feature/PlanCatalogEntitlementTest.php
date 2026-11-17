<?php

use App\Models\Plan;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

it('enables the public developer API for higher plans with bounded key and daily call allowances', function () {
    Artisan::call('db:seed', ['--class' => PlanSeeder::class]);

    $professional = Plan::query()->where('key', 'professional')->firstOrFail();
    $enterprise = Plan::query()->where('key', 'enterprise')->firstOrFail();
    $starter = Plan::query()->where('key', 'starter')->firstOrFail();

    expect($professional->entitlements()->where('entitlement_key', 'api.public')->value('value'))
        ->toBe('true')
        ->and($professional->entitlements()->where('entitlement_key', 'limits.api.keys.max')->value('value'))
        ->toBe('5')
        ->and($professional->entitlements()->where('entitlement_key', 'limits.api.daily_calls')->value('value'))
        ->toBe('10000')
        ->and($enterprise->entitlements()->where('entitlement_key', 'api.public')->value('value'))
        ->toBe('true')
        ->and($enterprise->entitlements()->where('entitlement_key', 'limits.api.keys.max')->value('value'))
        ->toBe('25')
        ->and($enterprise->entitlements()->where('entitlement_key', 'limits.api.daily_calls')->value('value'))
        ->toBe('100000')
        ->and($starter->entitlements()->where('entitlement_key', 'api.public')->exists())
        ->toBeFalse()
        ->and($professional->entitlements()->where('entitlement_key', 'channels.concierge')->value('value'))
        ->toBe('true')
        ->and($enterprise->entitlements()->where('entitlement_key', 'channels.concierge')->value('value'))
        ->toBe('true')
        ->and($starter->entitlements()->where('entitlement_key', 'channels.concierge')->exists())
        ->toBeFalse();
});
