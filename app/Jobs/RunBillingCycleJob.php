<?php

namespace App\Jobs;

use App\Models\Subscription;
use App\Services\BillingService;
use App\Services\DunningService;
use App\Support\TenantDatabaseContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Daily platform sweep: invoice every billable subscription for the current
 * month, then advance dunning. Runs across tenants, so it takes the platform
 * context rather than any one tenant.
 */
class RunBillingCycleJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('default');
    }

    /**
     * @return array{invoiced: int, advanced: int}
     */
    public function handle(BillingService $billing, DunningService $dunning, TenantDatabaseContext $context): array
    {
        /** @var array{invoiced: int, advanced: int} $result */
        $result = $context->run(null, null, function () use ($billing, $dunning): array {
            $invoiced = 0;
            $advanced = 0;

            Subscription::query()
                ->with(['plan', 'tenant'])
                ->whereNotIn('status', [Subscription::STATUS_CANCELLED])
                ->orderBy('id')
                ->chunkById(100, function ($subscriptions) use ($billing, $dunning, &$invoiced, &$advanced): void {
                    foreach ($subscriptions as $subscription) {
                        if ($this->isBillable($subscription) && $billing->generateInvoice($subscription, now())?->wasRecentlyCreated) {
                            $invoiced++;
                        }

                        if ($dunning->advance($subscription) !== null) {
                            $advanced++;
                        }
                    }
                });

            return ['invoiced' => $invoiced, 'advanced' => $advanced];
        }, isGlobalAdmin: true);

        Log::info('Billing cycle completed.', $result);

        return $result;
    }

    private function isBillable(Subscription $subscription): bool
    {
        if ($subscription->status === Subscription::STATUS_RESTRICTED) {
            return false;
        }

        if ($subscription->status === Subscription::STATUS_TRIALING) {
            return $subscription->trial_ends_at !== null && $subscription->trial_ends_at->lte(now());
        }

        return true;
    }
}
