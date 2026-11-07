<?php

use App\Jobs\RunBillingCycleJob;
use App\Models\BillingInvoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Notifications\SubscriptionStateNotification;
use App\Services\DunningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

function dunnedSubscription(string $status = Subscription::STATUS_ACTIVE, ?string $email = 'billing@example.test'): Subscription
{
    $tenant = Tenant::factory()->create(['billing_email' => $email]);

    return Subscription::factory()->for($tenant)->create(['status' => $status]);
}

test('an unpaid invoice past its due date moves an active subscription to past due', function () {
    Notification::fake();
    $subscription = dunnedSubscription();
    BillingInvoice::factory()->for($subscription)->create(['due_at' => now()->subDay()]);

    $result = app(DunningService::class)->advance($subscription);

    expect($result)->toBe(Subscription::STATUS_PAST_DUE)
        ->and($subscription->fresh()->past_due_at)->not->toBeNull();
    Notification::assertSentOnDemand(SubscriptionStateNotification::class);
});

test('an invoice that is not yet due leaves the subscription alone', function () {
    Notification::fake();
    $subscription = dunnedSubscription();
    BillingInvoice::factory()->for($subscription)->create();

    expect(app(DunningService::class)->advance($subscription))->toBeNull()
        ->and($subscription->fresh()->status)->toBe(Subscription::STATUS_ACTIVE);
    Notification::assertNothingSent();
});

test('a paid invoice never triggers dunning', function () {
    $subscription = dunnedSubscription();
    BillingInvoice::factory()->for($subscription)->overdue()->paid()->create();

    expect(app(DunningService::class)->advance($subscription))->toBeNull();
});

test('past due becomes grace after the past-due window and restricted after grace', function () {
    Notification::fake();
    $subscription = dunnedSubscription();
    $invoice = BillingInvoice::factory()->for($subscription)->create(['due_at' => now()->subDays(8)]);

    expect(app(DunningService::class)->advance($subscription, now()))->toBe(Subscription::STATUS_GRACE)
        ->and($subscription->fresh()->grace_ends_at->toDateString())
        ->toBe($invoice->due_at->copy()->addDays(7 + 14)->toDateString());

    expect(app(DunningService::class)->advance($subscription->fresh(), now()->addDays(14)))
        ->toBe(Subscription::STATUS_RESTRICTED)
        ->and($subscription->fresh()->restricted_at)->not->toBeNull();
});

test('a long outage catches up to restricted in a single run', function () {
    Notification::fake();
    $subscription = dunnedSubscription();
    BillingInvoice::factory()->for($subscription)->create(['due_at' => now()->subDays(60)]);

    expect(app(DunningService::class)->advance($subscription))->toBe(Subscription::STATUS_RESTRICTED);
    Notification::assertSentOnDemandTimes(SubscriptionStateNotification::class, 1);
});

test('cancelled and restricted subscriptions are not advanced', function (string $status) {
    $subscription = dunnedSubscription($status);
    BillingInvoice::factory()->for($subscription)->overdue()->create();

    expect(app(DunningService::class)->advance($subscription))->toBeNull()
        ->and($subscription->fresh()->status)->toBe($status);
})->with([
    'cancelled' => Subscription::STATUS_CANCELLED,
    'restricted' => Subscription::STATUS_RESTRICTED,
]);

test('no email is sent when the tenant has no billing address', function () {
    Notification::fake();
    $subscription = dunnedSubscription(email: null);
    BillingInvoice::factory()->for($subscription)->create(['due_at' => now()->subDay()]);

    expect(app(DunningService::class)->advance($subscription))->toBe(Subscription::STATUS_PAST_DUE);
    Notification::assertNothingSent();
});

test('the daily cycle invoices billable subscriptions and skips restricted and unfinished trials', function () {
    Notification::fake();
    $plan = Plan::factory()->create(['monthly_price_minor' => 5_000_000]);
    $active = Subscription::factory()->for($plan)->create();
    $trialing = Subscription::factory()->for($plan)->trialing()->create();
    $endedTrial = Subscription::factory()->for($plan)->create([
        'status' => Subscription::STATUS_TRIALING,
        'trial_ends_at' => now()->subDay(),
    ]);
    $restricted = Subscription::factory()->for($plan)->restricted()->create();

    $result = app()->call([new RunBillingCycleJob, 'handle']);

    expect($result['invoiced'])->toBe(2)
        ->and(BillingInvoice::query()->where('subscription_id', $active->id)->exists())->toBeTrue()
        ->and(BillingInvoice::query()->where('subscription_id', $endedTrial->id)->exists())->toBeTrue()
        ->and(BillingInvoice::query()->where('subscription_id', $trialing->id)->exists())->toBeFalse()
        ->and(BillingInvoice::query()->where('subscription_id', $restricted->id)->exists())->toBeFalse();
});

test('running the daily cycle twice does not double-invoice', function () {
    $plan = Plan::factory()->create(['monthly_price_minor' => 5_000_000]);
    Subscription::factory()->for($plan)->create();

    app()->call([new RunBillingCycleJob, 'handle']);
    $second = app()->call([new RunBillingCycleJob, 'handle']);

    expect(BillingInvoice::query()->count())->toBe(1)
        ->and($second['invoiced'])->toBe(0);
});
