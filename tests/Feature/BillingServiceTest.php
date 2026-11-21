<?php

use App\Models\ApiKey;
use App\Models\ApiKeyDailyUsage;
use App\Models\BillingInvoice;
use App\Models\Branch;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\Subscription;
use App\Models\TenantUsageDaily;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function billedSubscription(int $priceMinor = 10_000_000, string $status = Subscription::STATUS_ACTIVE): Subscription
{
    $plan = Plan::factory()->create(['monthly_price_minor' => $priceMinor, 'name' => 'Growth']);

    return Subscription::factory()->for($plan)->create(['status' => $status]);
}

test('issues a monthly invoice with the plan price, VAT and a frozen line', function () {
    $subscription = billedSubscription();

    $invoice = app(BillingService::class)->generateInvoice($subscription, now()->setDate(2026, 12, 15));

    expect($invoice)->not->toBeNull()
        ->and($invoice->subtotal_minor)->toBe(10_000_000)
        ->and($invoice->tax_minor)->toBe(750_000)
        ->and($invoice->total_minor)->toBe(10_750_000)
        ->and($invoice->period_start->toDateString())->toBe('2026-12-01')
        ->and($invoice->period_end->toDateString())->toBe('2026-12-31')
        ->and($invoice->number)->toBe('HOS-202612-'.$subscription->id)
        ->and($invoice->lines)->toHaveCount(1)
        ->and($invoice->lines->first()->description)->toBe('Growth plan, December 2026')
        ->and($invoice->lines->first()->amount_minor)->toBe(10_000_000);
});

test('generating the same period twice returns the same invoice', function () {
    $subscription = billedSubscription();
    $service = app(BillingService::class);

    $first = $service->generateInvoice($subscription, now()->setDate(2026, 12, 3));
    $second = $service->generateInvoice($subscription, now()->setDate(2026, 12, 28));

    expect($second->id)->toBe($first->id)
        ->and(BillingInvoice::query()->count())->toBe(1)
        ->and($first->lines()->count())->toBe(1);
});

test('invoice generation uses the plan attached when the subscription row is locked', function () {
    $subscription = billedSubscription(10_000_000);
    $subscription->load('plan');
    $subscription->plan->update(['monthly_price_minor' => 12_000_000]);

    $invoice = app(BillingService::class)->generateInvoice($subscription, now());

    expect($invoice)->not->toBeNull()
        ->and($invoice->subtotal_minor)->toBe(12_000_000);
});

test('adds metered api overage to the invoice when the tenant exceeded the plan allowance', function () {
    config(['billing.overage_rate_minor' => 500]);

    $plan = Plan::factory()->create(['monthly_price_minor' => 10_000_000, 'name' => 'Growth']);
    PlanEntitlement::create([
        'plan_id' => $plan->id,
        'entitlement_key' => 'limits.api.daily_calls',
        'value_type' => 'integer_limit',
        'value' => '10',
    ]);
    $subscription = Subscription::factory()->for($plan)->create();
    $tenantId = $subscription->tenant_id;

    $branch = Branch::factory()->create(['tenant_id' => $tenantId]);
    $apiKey = ApiKey::factory()->create(['tenant_id' => $tenantId, 'branch_id' => $branch->id]);
    ApiKeyDailyUsage::factory()->create([
        'api_key_id' => $apiKey->id,
        'usage_date' => now()->startOfMonth()->toDateString(),
        'request_count' => 350,
    ]);

    $invoice = app(BillingService::class)->generateInvoice($subscription, now());

    expect($invoice)->not->toBeNull()
        ->and($invoice->subtotal_minor)->toBe(10_000_000 + (350 - 300) * 500)
        ->and($invoice->lines)->toHaveCount(2)
        ->and($invoice->lines()->get()->last()->description)->toContain('API overage')
        ->and($invoice->lines()->get()->last()->amount_minor)->toBe(50 * 500);
});

test('does not bill a contract plan priced at zero', function () {
    $subscription = billedSubscription(0);

    expect(app(BillingService::class)->generateInvoice($subscription, now()))->toBeNull()
        ->and(BillingInvoice::query()->count())->toBe(0);
});

test('adds metered sms overage to the invoice when the tenant exceeded the plan allowance', function () {
    config(['billing.overage_rate_minor' => 500]);

    $plan = Plan::factory()->create(['monthly_price_minor' => 10_000_000, 'name' => 'Growth']);
    PlanEntitlement::create([
        'plan_id' => $plan->id,
        'entitlement_key' => 'limits.sms.monthly',
        'value_type' => 'integer_limit',
        'value' => '10',
    ]);
    $subscription = Subscription::factory()->for($plan)->create();
    $tenantId = $subscription->tenant_id;

    $branch = Branch::factory()->create(['tenant_id' => $tenantId]);
    TenantUsageDaily::create([
        'tenant_id' => $tenantId,
        'branch_id' => $branch->id,
        'usage_date' => now()->startOfMonth()->toDateString(),
        'metric' => 'sms',
        'count' => 25,
    ]);

    $invoice = app(BillingService::class)->generateInvoice($subscription, now());

    expect($invoice)->not->toBeNull()
        ->and($invoice->subtotal_minor)->toBe(10_000_000 + (25 - 10) * 500)
        ->and($invoice->lines)->toHaveCount(2)
        ->and($invoice->lines()->get()->last()->description)->toContain('Outbound SMS overage')
        ->and($invoice->lines()->get()->last()->amount_minor)->toBe(15 * 500);
});

test('adds metered ai concierge overage to the invoice when the tenant exceeded the plan allowance', function () {
    config(['billing.overage_rate_minor' => 250]);

    $plan = Plan::factory()->create(['monthly_price_minor' => 10_000_000, 'name' => 'Growth']);
    PlanEntitlement::create([
        'plan_id' => $plan->id,
        'entitlement_key' => 'limits.ai.conversations_monthly',
        'value_type' => 'integer_limit',
        'value' => '5',
    ]);
    $subscription = Subscription::factory()->for($plan)->create();
    $tenantId = $subscription->tenant_id;

    $branch = Branch::factory()->create(['tenant_id' => $tenantId]);
    TenantUsageDaily::create([
        'tenant_id' => $tenantId,
        'branch_id' => $branch->id,
        'usage_date' => now()->startOfMonth()->toDateString(),
        'metric' => 'ai',
        'count' => 8,
    ]);

    $invoice = app(BillingService::class)->generateInvoice($subscription, now());

    expect($invoice)->not->toBeNull()
        ->and($invoice->subtotal_minor)->toBe(10_000_000 + (8 - 5) * 250)
        ->and($invoice->lines)->toHaveCount(2)
        ->and($invoice->lines()->get()->last()->description)->toContain('AI concierge conversation overage')
        ->and($invoice->lines()->get()->last()->amount_minor)->toBe(3 * 250);
});

test('a matching payment marks the invoice paid and reactivates a delinquent subscription', function () {
    $subscription = billedSubscription(status: Subscription::STATUS_GRACE);
    $subscription->update(['past_due_at' => now()->subDays(10), 'grace_ends_at' => now()->addDays(4)]);
    $invoice = app(BillingService::class)->generateInvoice($subscription, now());

    app(BillingService::class)->reconcilePayment($invoice, 'PAY-1', $invoice->total_minor, 'NGN');

    $subscription->refresh();

    expect($invoice->fresh()->status)->toBe(BillingInvoice::STATUS_PAID)
        ->and($invoice->fresh()->payment_reference)->toBe('PAY-1')
        ->and($subscription->status)->toBe(Subscription::STATUS_ACTIVE)
        ->and($subscription->past_due_at)->toBeNull()
        ->and($subscription->grace_ends_at)->toBeNull();
});

test('paying restores a restricted subscription', function () {
    $subscription = billedSubscription(status: Subscription::STATUS_RESTRICTED);
    $invoice = app(BillingService::class)->generateInvoice($subscription, now());

    app(BillingService::class)->reconcilePayment($invoice, 'PAY-2', $invoice->total_minor, 'NGN');

    expect($subscription->fresh()->status)->toBe(Subscription::STATUS_ACTIVE)
        ->and($subscription->fresh()->restricted_at)->toBeNull();
});

test('rejects a payment whose amount does not match the invoice', function () {
    $subscription = billedSubscription();
    $invoice = app(BillingService::class)->generateInvoice($subscription, now());

    expect(fn () => app(BillingService::class)->reconcilePayment($invoice, 'PAY-3', $invoice->total_minor - 1, 'NGN'))
        ->toThrow(DomainException::class, 'does not match');
    expect($invoice->fresh()->status)->toBe(BillingInvoice::STATUS_ISSUED);
});

test('rejects a payment in the wrong currency', function () {
    $subscription = billedSubscription();
    $invoice = app(BillingService::class)->generateInvoice($subscription, now());

    expect(fn () => app(BillingService::class)->reconcilePayment($invoice, 'PAY-4', $invoice->total_minor, 'USD'))
        ->toThrow(DomainException::class, 'does not match');
});

test('replaying the same payment reference changes nothing, a different one is refused', function () {
    $subscription = billedSubscription();
    $service = app(BillingService::class);
    $invoice = $service->generateInvoice($subscription, now());

    $service->reconcilePayment($invoice, 'PAY-5', $invoice->total_minor, 'NGN');
    $paidAt = $invoice->fresh()->paid_at;
    $service->reconcilePayment($invoice, 'PAY-5', $invoice->total_minor, 'NGN');

    expect($invoice->fresh()->paid_at->equalTo($paidAt))->toBeTrue()
        ->and(fn () => $service->reconcilePayment($invoice, 'PAY-6', $invoice->total_minor, 'NGN'))
        ->toThrow(DomainException::class, 'different reference');
});

test('refuses to pay a void invoice', function () {
    $subscription = billedSubscription();
    $invoice = app(BillingService::class)->generateInvoice($subscription, now());
    $invoice->update(['status' => BillingInvoice::STATUS_VOID]);

    expect(fn () => app(BillingService::class)->reconcilePayment($invoice, 'PAY-7', $invoice->total_minor, 'NGN'))
        ->toThrow(DomainException::class, 'void');
});

test('a cancelled subscription stays cancelled after a late payment', function () {
    $subscription = billedSubscription(status: Subscription::STATUS_CANCELLED);
    $invoice = app(BillingService::class)->generateInvoice($subscription, now());

    app(BillingService::class)->reconcilePayment($invoice, 'PAY-8', $invoice->total_minor, 'NGN');

    expect($subscription->fresh()->status)->toBe(Subscription::STATUS_CANCELLED);
});
