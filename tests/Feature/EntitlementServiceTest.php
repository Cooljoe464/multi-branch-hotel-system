<?php

use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantEntitlementOverride;
use App\Services\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  list<string>  $features
 * @param  array<string, int>  $limits
 */
function subscribedPlan(array $features, array $limits = []): Plan
{
    $plan = Plan::factory()->create();

    foreach ($features as $feature) {
        PlanEntitlement::create([
            'plan_id' => $plan->id,
            'entitlement_key' => $feature,
            'value_type' => PlanEntitlement::TYPE_BOOLEAN,
            'value' => 'true',
        ]);
    }

    foreach ($limits as $key => $limit) {
        PlanEntitlement::create([
            'plan_id' => $plan->id,
            'entitlement_key' => $key,
            'value_type' => PlanEntitlement::TYPE_INTEGER_LIMIT,
            'value' => (string) $limit,
        ]);
    }

    return $plan;
}

test('a tenant is entitled to what its plan grants and nothing else', function () {
    $tenant = Tenant::factory()->create();
    Subscription::factory()->for($tenant)->for(subscribedPlan(['pms.reservations']))->create();

    $service = app(EntitlementService::class);

    expect($service->allows($tenant->id, 'pms.reservations'))->toBeTrue()
        ->and($service->allows($tenant->id, 'pos.sales'))->toBeFalse();
});

test('a tenant without a subscription keeps full access while enforcement is off', function () {
    $tenant = Tenant::factory()->create();

    expect(app(EntitlementService::class)->allows($tenant->id, 'pos.sales'))->toBeTrue();
});

test('a tenant without a subscription is denied everything once enforcement is on', function () {
    config(['entitlements.enforce_without_subscription' => true]);
    $tenant = Tenant::factory()->create();

    expect(app(EntitlementService::class)->allows($tenant->id, 'pms.reservations'))->toBeFalse();
});

test('a request with no resolvable tenant is not blocked', function () {
    config(['entitlements.enforce_without_subscription' => true]);

    expect(app(EntitlementService::class)->allows(null, 'pos.sales'))->toBeTrue();
});

test('an unexpired override grants an entitlement the plan lacks', function () {
    $tenant = Tenant::factory()->create();
    Subscription::factory()->for($tenant)->for(subscribedPlan(['pms.reservations']))->create();
    TenantEntitlementOverride::factory()->for($tenant)->create(['entitlement_key' => 'pos.sales']);

    expect(app(EntitlementService::class)->allows($tenant->id, 'pos.sales'))->toBeTrue();
});

test('an expired override is ignored', function () {
    $tenant = Tenant::factory()->create();
    Subscription::factory()->for($tenant)->for(subscribedPlan(['pms.reservations']))->create();
    TenantEntitlementOverride::factory()->for($tenant)->expired()->create(['entitlement_key' => 'pos.sales']);

    expect(app(EntitlementService::class)->allows($tenant->id, 'pos.sales'))->toBeFalse();
});

test('trialing, past due and grace subscriptions keep every plan entitlement', function (string $state) {
    $tenant = Tenant::factory()->create();
    Subscription::factory()->for($tenant)->for(subscribedPlan(['pos.sales']))->create(['status' => $state]);

    expect(app(EntitlementService::class)->allows($tenant->id, 'pos.sales'))->toBeTrue();
})->with([
    'trialing' => Subscription::STATUS_TRIALING,
    'past due' => Subscription::STATUS_PAST_DUE,
    'grace' => Subscription::STATUS_GRACE,
]);

test('restricted and cancelled subscriptions block premium modules but keep checkout safety entitlements', function (string $state) {
    $tenant = Tenant::factory()->create();
    $plan = subscribedPlan(['pms.reservations', 'pos.sales', 'finance.folios', 'pms.front_desk', 'locks.issue', 'wifi.vouchers']);
    Subscription::factory()->for($tenant)->for($plan)->create(['status' => $state]);

    $service = app(EntitlementService::class);

    expect($service->allows($tenant->id, 'pms.reservations'))->toBeFalse()
        ->and($service->allows($tenant->id, 'pos.sales'))->toBeFalse()
        ->and($service->allows($tenant->id, 'finance.folios'))->toBeTrue()
        ->and($service->allows($tenant->id, 'pms.front_desk'))->toBeTrue()
        ->and($service->allows($tenant->id, 'locks.issue'))->toBeTrue()
        ->and($service->allows($tenant->id, 'wifi.vouchers'))->toBeTrue();
})->with([
    'restricted' => Subscription::STATUS_RESTRICTED,
    'cancelled' => Subscription::STATUS_CANCELLED,
]);

test('a restriction does not let an override bring back a blocked premium module', function () {
    $tenant = Tenant::factory()->create();
    Subscription::factory()->for($tenant)->for(subscribedPlan(['finance.folios']))->restricted()->create();
    TenantEntitlementOverride::factory()->for($tenant)->create(['entitlement_key' => 'pos.sales']);

    expect(app(EntitlementService::class)->allows($tenant->id, 'pos.sales'))->toBeFalse();
});

test('a numeric limit is returned from the plan and drops to zero when restricted', function () {
    $tenant = Tenant::factory()->create();
    $subscription = Subscription::factory()->for($tenant)->for(subscribedPlan([], ['limits.rooms.max' => 50]))->create();

    $service = app(EntitlementService::class);

    expect($service->limit($tenant->id, 'limits.rooms.max'))->toBe(50);

    $subscription->update(['status' => Subscription::STATUS_RESTRICTED]);

    expect($service->limit($tenant->id, 'limits.rooms.max'))->toBe(0);
});

test('changing a subscription takes effect immediately despite caching', function () {
    $tenant = Tenant::factory()->create();
    $subscription = Subscription::factory()->for($tenant)->for(subscribedPlan(['pos.sales']))->create();
    $service = app(EntitlementService::class);

    expect($service->allows($tenant->id, 'pos.sales'))->toBeTrue();

    $subscription->update(['status' => Subscription::STATUS_CANCELLED]);

    expect($service->allows($tenant->id, 'pos.sales'))->toBeFalse();
});

test('one tenant restriction does not affect another tenant on the same plan', function () {
    $plan = subscribedPlan(['pos.sales']);
    $restricted = Tenant::factory()->create();
    $healthy = Tenant::factory()->create();
    Subscription::factory()->for($restricted)->for($plan)->restricted()->create();
    Subscription::factory()->for($healthy)->for($plan)->create();

    $service = app(EntitlementService::class);

    expect($service->allows($restricted->id, 'pos.sales'))->toBeFalse()
        ->and($service->allows($healthy->id, 'pos.sales'))->toBeTrue();
});
