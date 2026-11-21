<?php

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('starts a trialing subscription with a trial end date for a new tenant', function () {
    $tenant = Tenant::factory()->create();
    $plan = Plan::factory()->create(['key' => 'starter', 'is_active' => true]);

    $subscription = app(SubscriptionService::class)->startTrial($tenant, 'starter');

    expect($subscription->id)->toBeGreaterThan(0)
        ->and($subscription->status)->toBe(Subscription::STATUS_TRIALING)
        ->and($subscription->trial_ends_at)->not->toBeEmpty()
        ->and($subscription->plan_id)->toBe($plan->id);
});

it('does not overwrite an existing subscription when starting a trial', function () {
    $tenant = Tenant::factory()->create();
    $plan = Plan::factory()->create(['key' => 'growth', 'is_active' => true]);
    $existing = Subscription::factory()->create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => Subscription::STATUS_ACTIVE]);

    $subscription = app(SubscriptionService::class)->startTrial($tenant, 'growth');

    expect($subscription?->id)->toBe($existing->id)
        ->and($subscription->status)->toBe(Subscription::STATUS_ACTIVE);
});
