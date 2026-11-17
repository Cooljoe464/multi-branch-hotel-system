<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Models\ApiKeyDailyUsage;
use App\Models\Branch;
use App\Support\BranchTime;
use Illuminate\Support\Facades\DB;

class PublicApiUsageService
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    /**
     * Count one authenticated API request and return seconds until reset if
     * the tenant's daily allowance has already been exhausted.
     */
    public function consume(ApiKey $apiKey, Branch $branch): ?int
    {
        $limit = $this->entitlements->limit($branch->tenant_id, 'limits.api.daily_calls');
        $today = BranchTime::today($branch);

        return DB::transaction(function () use ($apiKey, $branch, $limit, $today): ?int {
            $lockedKey = ApiKey::query()->whereKey($apiKey->id)->lockForUpdate()->firstOrFail();

            if (! $lockedKey->usable()) {
                abort(401, 'Invalid public API key.');
            }

            $usage = ApiKeyDailyUsage::query()
                ->where('api_key_id', $lockedKey->id)
                ->whereDate('usage_date', $today)
                ->lockForUpdate()
                ->first();

            if ($usage === null) {
                $usage = ApiKeyDailyUsage::create([
                    'tenant_id' => $branch->tenant_id,
                    'branch_id' => $branch->id,
                    'api_key_id' => $lockedKey->id,
                    'usage_date' => $today,
                    'request_count' => 0,
                ]);
            }

            if ($limit !== null && $usage->request_count >= $limit) {
                $now = BranchTime::now($branch);

                return max(1, (int) $now->diffInSeconds($now->copy()->addDay()->startOfDay()));
            }

            $usage->increment('request_count');

            return null;
        }, 3);
    }
}
