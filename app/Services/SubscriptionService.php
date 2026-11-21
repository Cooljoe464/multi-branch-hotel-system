<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;

/**
 * Commercial lifecycle for tenants: starts trial subscriptions for
 * freshly provisioned properties and lets the platform control layer
 * reconcile plans without touching the provisioning path.
 */
class SubscriptionService
{
    /**
     * Start a trial subscription on the given active plan key for a
     * tenant. Existing subscriptions are preserved so platform-owned
     * plan changes keep applying through the platform screen.
     */
    public function startTrial(Tenant $tenant, string $planKey, int $trialDays = 30): ?Subscription
    {
        $plan = Plan::query()->where('key', $planKey)->where('is_active', true)->first();

        if ($plan === null) {
            return null;
        }

        $subscription = Subscription::query()->where('tenant_id', $tenant->id)->first();

        if ($subscription !== null) {
            $subscription->update(['plan_id' => $plan->id]);

            return $subscription;
        }

        return Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_TRIALING,
            'trial_ends_at' => now()->addDays($trialDays),
        ]);
    }
}
