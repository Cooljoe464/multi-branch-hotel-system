<?php

use App\Models\ApiConsumer;
use App\Models\BillingInvoice;
use App\Models\Branch;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  list<string>  $scopes
 */
function saasToken(Branch $branch, array $scopes = ['saas.view']): string
{
    $consumer = ApiConsumer::create([
        'name' => 'SaaS Consumer',
        'branch_id' => $branch->id,
        'branch_ids' => null,
        'scopes' => $scopes,
        'is_active' => true,
    ]);

    return $consumer->createToken('test', $consumer->tokenAbilities())->plainTextToken;
}

test('returns 401 when no token is provided', function () {
    $this->getJson('/api/v1/me/entitlements')->assertUnauthorized();
});

test('returns 403 when the token lacks the saas.view scope', function () {
    $branch = Branch::factory()->create();

    $this->withToken(saasToken($branch, ['availability.view']))
        ->getJson('/api/v1/saas/subscription')
        ->assertForbidden();
});

test('reports the resolved entitlements of the token property tenant', function () {
    $tenant = Tenant::factory()->create();
    $branch = Branch::factory()->for($tenant)->create();
    $plan = Plan::factory()->create();
    PlanEntitlement::create(['plan_id' => $plan->id, 'entitlement_key' => 'pos.sales', 'value_type' => 'boolean', 'value' => 'true']);
    PlanEntitlement::create(['plan_id' => $plan->id, 'entitlement_key' => 'limits.rooms.max', 'value_type' => 'integer_limit', 'value' => '50']);
    Subscription::factory()->for($tenant)->for($plan)->create();

    $this->withToken(saasToken($branch))
        ->getJson('/api/v1/me/entitlements')
        ->assertOk()
        ->assertJsonPath('data.metered', true)
        ->assertJson(['data' => ['entitlements' => ['pos.sales' => true, 'limits.rooms.max' => 50]]]);
});

test('reports an unmetered tenant as such', function () {
    $branch = Branch::factory()->create();

    $this->withToken(saasToken($branch))
        ->getJson('/api/v1/me/entitlements')
        ->assertOk()
        ->assertJsonPath('data.metered', false);
});

test('returns the subscription status and plan', function () {
    $tenant = Tenant::factory()->create();
    $branch = Branch::factory()->for($tenant)->create();
    $plan = Plan::factory()->create(['key' => 'growth', 'name' => 'Growth']);
    Subscription::factory()->for($tenant)->for($plan)->pastDue()->create();

    $this->withToken(saasToken($branch))
        ->getJson('/api/v1/saas/subscription')
        ->assertOk()
        ->assertJsonPath('data.status', Subscription::STATUS_PAST_DUE)
        ->assertJsonPath('data.plan.key', 'growth');
});

test('lists only the token tenant invoices', function () {
    $tenant = Tenant::factory()->create();
    $branch = Branch::factory()->for($tenant)->create();
    $mine = Subscription::factory()->for($tenant)->create();
    $theirs = Subscription::factory()->create();
    BillingInvoice::factory()->for($mine)->create(['number' => 'HOS-MINE']);
    BillingInvoice::factory()->for($theirs)->create(['number' => 'HOS-THEIRS']);

    $this->withToken(saasToken($branch))
        ->getJson('/api/v1/saas/invoices')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.number', 'HOS-MINE');
});
