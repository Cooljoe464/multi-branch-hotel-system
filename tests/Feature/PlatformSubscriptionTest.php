<?php

use App\Models\BillingInvoice;
use App\Models\Branch;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantEntitlementOverride;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(RefreshDatabase::class);

function platformOwner(TestCase $test): User
{
    $test->seed(RoleSeeder::class);
    $owner = User::factory()->create(['is_global_admin' => true]);
    $owner->assignRole('Global Admin');

    return $owner;
}

function tenantAdmin(TestCase $test, Branch $branch): User
{
    $test->seed(RoleSeeder::class);
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $user->assignRole('Global Admin');
    $user->branches()->syncWithoutDetaching([$branch->id]);

    return $user;
}

test('forbids a tenant admin from the platform subscriptions page', function () {
    $branch = Branch::factory()->create();

    $this->actingAs(tenantAdmin($this, $branch))->get('/platform/subscriptions')->assertForbidden();
});

test('forbids a tenant admin from assigning themselves a plan', function () {
    $branch = Branch::factory()->create();
    $plan = Plan::factory()->create();

    $this->actingAs(tenantAdmin($this, $branch))
        ->post("/platform/tenants/{$branch->tenant_id}/plan", ['plan_id' => $plan->id])
        ->assertForbidden();

    expect(Subscription::query()->count())->toBe(0);
});

test('forbids a tenant admin from granting themselves an override', function () {
    $branch = Branch::factory()->create();

    $this->actingAs(tenantAdmin($this, $branch))
        ->post("/platform/tenants/{$branch->tenant_id}/overrides", [
            'entitlement_key' => 'pos.sales',
            'value_type' => 'boolean',
            'value' => 'true',
            'reason' => 'self grant',
            'expires_at' => now()->addMonth()->toDateTimeString(),
        ])
        ->assertForbidden();

    expect(TenantEntitlementOverride::query()->count())->toBe(0);
});

test('lists tenants and open invoices for the platform owner', function () {
    $tenant = Tenant::factory()->create(['name' => 'Lagos Grand']);
    $subscription = Subscription::factory()->for($tenant)->create();
    BillingInvoice::factory()->for($subscription)->create(['number' => 'HOS-OPEN']);

    $this->withoutVite()
        ->actingAs(platformOwner($this))
        ->get('/platform/subscriptions')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('platform/Subscriptions')
            ->where('tenants', fn ($tenants) => collect($tenants)->contains('name', 'Lagos Grand'))
            ->where('openInvoices', fn ($invoices) => collect($invoices)->contains('number', 'HOS-OPEN')));
});

test('puts a tenant without a subscription on a trial of the chosen plan', function () {
    $tenant = Tenant::factory()->create();
    $plan = Plan::factory()->create();

    $this->actingAs(platformOwner($this))
        ->post("/platform/tenants/{$tenant->id}/plan", ['plan_id' => $plan->id])
        ->assertRedirect();

    $subscription = Subscription::query()->where('tenant_id', $tenant->id)->firstOrFail();

    expect($subscription->plan_id)->toBe($plan->id)
        ->and($subscription->status)->toBe(Subscription::STATUS_TRIALING)
        ->and($subscription->trial_ends_at)->not->toBeNull();
});

test('changes the plan of an existing subscription without touching its status', function () {
    $subscription = Subscription::factory()->pastDue()->create();
    $newPlan = Plan::factory()->create();

    $this->actingAs(platformOwner($this))
        ->post("/platform/tenants/{$subscription->tenant_id}/plan", ['plan_id' => $newPlan->id])
        ->assertRedirect();

    expect($subscription->fresh()->plan_id)->toBe($newPlan->id)
        ->and($subscription->fresh()->status)->toBe(Subscription::STATUS_PAST_DUE);
});

test('rejects an inactive plan', function () {
    $tenant = Tenant::factory()->create();
    $plan = Plan::factory()->create(['is_active' => false]);

    $this->actingAs(platformOwner($this))
        ->post("/platform/tenants/{$tenant->id}/plan", ['plan_id' => $plan->id])
        ->assertSessionHasErrors('plan_id');
});

test('records a time-limited override with a reason', function () {
    $tenant = Tenant::factory()->create();
    $owner = platformOwner($this);

    $this->actingAs($owner)
        ->post("/platform/tenants/{$tenant->id}/overrides", [
            'entitlement_key' => 'pos.sales',
            'value_type' => 'boolean',
            'value' => 'true',
            'reason' => 'Pilot extension',
            'expires_at' => now()->addMonth()->toDateTimeString(),
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('tenant_entitlement_overrides', [
        'tenant_id' => $tenant->id,
        'entitlement_key' => 'pos.sales',
        'reason' => 'Pilot extension',
        'created_by' => $owner->id,
    ]);
});

test('requires an override to expire in the future', function () {
    $tenant = Tenant::factory()->create();

    $this->actingAs(platformOwner($this))
        ->post("/platform/tenants/{$tenant->id}/overrides", [
            'entitlement_key' => 'pos.sales',
            'value_type' => 'boolean',
            'value' => 'true',
            'reason' => 'Backdated',
            'expires_at' => now()->subDay()->toDateTimeString(),
        ])
        ->assertSessionHasErrors('expires_at');
});

test('reconciles a matching manual payment and reactivates the subscription', function () {
    $subscription = Subscription::factory()->restricted()->create();
    $invoice = BillingInvoice::factory()->for($subscription)->create();

    $this->actingAs(platformOwner($this))
        ->post("/platform/invoices/{$invoice->id}/payments", [
            'payment_reference' => 'BANK-77',
            'amount_minor' => $invoice->total_minor,
            'currency' => 'NGN',
        ])
        ->assertRedirect();

    expect($invoice->fresh()->status)->toBe(BillingInvoice::STATUS_PAID)
        ->and($subscription->fresh()->status)->toBe(Subscription::STATUS_ACTIVE);
});

test('refuses a manual payment that does not match the invoice amount', function () {
    $subscription = Subscription::factory()->create();
    $invoice = BillingInvoice::factory()->for($subscription)->create();

    $this->actingAs(platformOwner($this))
        ->post("/platform/invoices/{$invoice->id}/payments", [
            'payment_reference' => 'BANK-78',
            'amount_minor' => $invoice->total_minor - 100,
            'currency' => 'NGN',
        ])
        ->assertSessionHasErrors('payment');

    expect($invoice->fresh()->status)->toBe(BillingInvoice::STATUS_ISSUED);
});
