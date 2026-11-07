<?php

use App\Models\BillingInvoice;
use App\Models\Plan;
use App\Models\Subscription;
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

test('does not bill a contract plan priced at zero', function () {
    $subscription = billedSubscription(0);

    expect(app(BillingService::class)->generateInvoice($subscription, now()))->toBeNull()
        ->and(BillingInvoice::query()->count())->toBe(0);
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
