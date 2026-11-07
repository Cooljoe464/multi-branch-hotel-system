<?php

namespace App\Services;

use App\Models\BillingInvoice;
use App\Models\Subscription;
use App\Notifications\SubscriptionStateNotification;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Notification;

/**
 * Moves a subscription along trialing/active -> past_due -> grace ->
 * restricted when invoices go unpaid. Durations come from config/billing.php.
 * Cancelled and already restricted subscriptions are left alone.
 */
class DunningService
{
    /**
     * Apply every transition that is due as of $now.
     *
     * @return string|null the final status when it changed, otherwise null
     */
    public function advance(Subscription $subscription, ?CarbonInterface $now = null): ?string
    {
        $now ??= now();
        $startingStatus = $subscription->status;

        while ($this->step($subscription, $now)) {
            // Keep stepping until nothing more is due, so a long outage catches up in one run.
        }

        if ($subscription->status === $startingStatus) {
            return null;
        }

        $this->notify($subscription);

        return $subscription->status;
    }

    private function step(Subscription $subscription, CarbonInterface $now): bool
    {
        $pastDueDays = config()->integer('billing.past_due_days');
        $graceDays = config()->integer('billing.grace_days');

        switch ($subscription->status) {
            case Subscription::STATUS_TRIALING:
            case Subscription::STATUS_ACTIVE:
                $overdue = BillingInvoice::query()
                    ->where('subscription_id', $subscription->id)
                    ->where('status', BillingInvoice::STATUS_ISSUED)
                    ->where('due_at', '<=', $now)
                    ->orderBy('due_at')
                    ->first();

                if ($overdue === null) {
                    return false;
                }

                $subscription->update([
                    'status' => Subscription::STATUS_PAST_DUE,
                    'past_due_at' => $overdue->due_at,
                ]);

                return true;

            case Subscription::STATUS_PAST_DUE:
                $pastDueAt = $subscription->past_due_at ?? $now;

                if ($pastDueAt->copy()->addDays($pastDueDays)->gt($now)) {
                    return false;
                }

                $subscription->update([
                    'status' => Subscription::STATUS_GRACE,
                    'grace_ends_at' => $pastDueAt->copy()->addDays($pastDueDays + $graceDays),
                ]);

                return true;

            case Subscription::STATUS_GRACE:
                if ($subscription->grace_ends_at === null || $subscription->grace_ends_at->gt($now)) {
                    return false;
                }

                $subscription->update([
                    'status' => Subscription::STATUS_RESTRICTED,
                    'restricted_at' => $subscription->grace_ends_at,
                ]);

                return true;

            default:
                return false;
        }
    }

    private function notify(Subscription $subscription): void
    {
        $subscription->loadMissing('tenant');
        $email = $subscription->tenant->billing_email;

        if ($email === null || $email === '') {
            return;
        }

        Notification::route('mail', $email)->notify(new SubscriptionStateNotification($subscription));
    }
}
