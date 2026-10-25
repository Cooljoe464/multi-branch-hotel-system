<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Outbound vendor call budget. Plain Laravel RateLimiter buckets keyed
 * by vendor (and branch when the credential is branch-scoped), so one
 * property hammering FIRS/OCR cannot exhaust the shared quota.
 */
class VendorRateLimiter
{
    /**
     * @param  int|null  $branchId  Null for account-wide vendor quotas.
     */
    public function attempt(string $vendor, ?int $branchId, int $maxAttempts, int $decaySeconds = 60): bool
    {
        $key = $this->key($vendor, $branchId);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            return false;
        }

        RateLimiter::hit($key, $decaySeconds);

        return true;
    }

    /**
     * Seconds until the bucket frees up — the caller's retry delay.
     */
    public function availableIn(string $vendor, ?int $branchId): int
    {
        return RateLimiter::availableIn($this->key($vendor, $branchId));
    }

    private function key(string $vendor, ?int $branchId): string
    {
        return 'vendor:'.$vendor.($branchId !== null ? ':'.$branchId : '');
    }
}
