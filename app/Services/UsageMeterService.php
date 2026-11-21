<?php

namespace App\Services;

use App\Models\ApiKeyDailyUsage;
use App\Models\Plan;
use App\Models\TenantUsageDaily;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Monthly usage metering across the metered channels and the overage
 * math applied to subscription invoices.
 */
class UsageMeterService
{
    /**
     * Count authenticated public API calls recorded against a tenant
     * during the billing month starting at $periodStart.
     */
    public function monthlyApiCalls(int $tenantId, CarbonInterface $periodStart): int
    {
        $month = $periodStart->format('Y-m');

        return (int) ApiKeyDailyUsage::query()
            ->where('tenant_id', $tenantId)
            ->where('usage_date', 'like', $month.'%')
            ->sum('request_count');
    }

    /**
     * Count outbound SMS dispatched for a tenant during the billing month
     * starting at $periodStart.
     */
    public function monthlyOutboundSms(int $tenantId, CarbonInterface $periodStart): int
    {
        return $this->monthlyMetric('sms', $tenantId, $periodStart);
    }

    /**
     * Count AI concierge conversations held for a tenant during the billing
     * month starting at $periodStart.
     */
    public function monthlyAiConversations(int $tenantId, CarbonInterface $periodStart): int
    {
        return $this->monthlyMetric('ai', $tenantId, $periodStart);
    }

    /**
     * Record one metered event against a tenant, branch and metric for the
     * day starting at $usageDate.
     */
    public function record(string $metric, int $tenantId, int $branchId, CarbonInterface $usageDate, int $count = 1): void
    {
        $usageDate = $usageDate->copy()->startOfDay();

        DB::transaction(function () use ($metric, $tenantId, $branchId, $usageDate, $count): void {
            $usage = TenantUsageDaily::query()
                ->where('tenant_id', $tenantId)
                ->where('branch_id', $branchId)
                ->where('metric', $metric)
                ->where('usage_date', $usageDate->toDateString())
                ->first();

            if ($usage === null) {
                TenantUsageDaily::create([
                    'tenant_id' => $tenantId,
                    'branch_id' => $branchId,
                    'usage_date' => $usageDate->toDateString(),
                    'metric' => $metric,
                    'count' => $count,
                ]);

                return;
            }

            $usage->increment('count', $count);
        });
    }

    /**
     * Sum a metered metric for a tenant across branches during the billing
     * month starting at $periodStart.
     */
    public function monthlyMetric(string $metric, int $tenantId, CarbonInterface $periodStart): int
    {
        $month = $periodStart->format('Y-m');

        return (int) TenantUsageDaily::query()
            ->where('tenant_id', $tenantId)
            ->where('metric', $metric)
            ->where('usage_date', 'like', $month.'%')
            ->sum('count') ?? 0;
    }

    /**
     * Overages owed for the period: metered usage above the plan allowance.
     */
    public function overageFor(Plan $plan, int $usage, int $allowance): int
    {
        return max(0, $usage - max(0, $allowance));
    }

    /**
     * Monthly allowance for a plan: the metered daily API-call ceiling
     * carried forward for every day of the billing month.
     */
    public function monthlyAllowance(Plan $plan, CarbonInterface $periodStart): int
    {
        $dailyLimit = $this->planApiDailyLimit($plan);

        if ($dailyLimit <= 0) {
            return 0;
        }

        $daysInMonth = (int) $periodStart->copy()->endOfMonth()->day;

        return $dailyLimit * max(1, $daysInMonth);
    }

    public function planApiDailyLimit(Plan $plan): int
    {
        $limit = $plan->entitlements()
            ->where('entitlement_key', 'limits.api.daily_calls')
            ->where('value_type', 'integer_limit')
            ->value('value');

        return max(0, (int) ($limit ?? 0));
    }

    /**
     * Monthly allowance for a metered channel: outbound SMS and AI
     * concierge conversations carry their own plan-level monthly quotas.
     */
    public function meteredAllowance(Plan $plan, string $metric, CarbonInterface $periodStart): int
    {
        $limitKey = match ($metric) {
            'sms' => 'limits.sms.monthly',
            'ai' => 'limits.ai.conversations_monthly',
            default => 'limits.api.daily_calls',
        };

        if ($metric === 'api') {
            return $this->monthlyAllowance($plan, $periodStart);
        }

        $value = $plan->entitlements()
            ->where('entitlement_key', $limitKey)
            ->where('value_type', 'integer_limit')
            ->value('value');

        return max(0, (int) ($value ?? 0));
    }
}
