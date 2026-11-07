<?php

namespace App\Services;

use App\Models\PlanEntitlement;
use App\Models\Subscription;
use App\Models\TenantEntitlementOverride;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves what a tenant may use, in the order the spec requires: the
 * subscription state first, then the base plan, then time-limited
 * commercial overrides. Role permissions are checked separately.
 */
class EntitlementService
{
    private const VERSION_KEY = 'entitlements:version';

    public function allows(?int $tenantId, string $key): bool
    {
        if ($tenantId === null) {
            return true;
        }

        $resolved = $this->resolve($tenantId);

        if ($resolved === null) {
            return ! config('entitlements.enforce_without_subscription');
        }

        return ($resolved[$key] ?? false) === true;
    }

    /**
     * The numeric limit for a key, or null when the tenant has no limit
     * recorded or entitlements are not enforced for it.
     */
    public function limit(?int $tenantId, string $key): ?int
    {
        if ($tenantId === null) {
            return null;
        }

        $value = ($this->resolve($tenantId) ?? [])[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * Every resolved entitlement for the tenant, or null when the tenant has
     * no subscription.
     *
     * @return array<string, bool|int>|null
     */
    public function resolve(int $tenantId): ?array
    {
        $storedVersion = Cache::get(self::VERSION_KEY);
        $version = is_int($storedVersion) ? $storedVersion : 1;
        $ttl = config()->integer('entitlements.cache_ttl');

        /** @var array{subscribed: bool, entitlements: array<string, bool|int>} $payload */
        $payload = Cache::remember(
            "entitlements:{$version}:tenant:{$tenantId}",
            $ttl,
            fn (): array => $this->compute($tenantId),
        );

        return $payload['subscribed'] ? $payload['entitlements'] : null;
    }

    /**
     * Invalidate every cached resolution. Call after any plan, subscription
     * or override change.
     */
    public function flush(): void
    {
        Cache::add(self::VERSION_KEY, 1);
        Cache::increment(self::VERSION_KEY);
    }

    /**
     * @return array{subscribed: bool, entitlements: array<string, bool|int>}
     */
    private function compute(int $tenantId): array
    {
        $subscription = Subscription::query()
            ->with('plan.entitlements')
            ->where('tenant_id', $tenantId)
            ->first();

        if ($subscription === null) {
            return ['subscribed' => false, 'entitlements' => []];
        }

        $entitlements = [];

        foreach ($subscription->plan->entitlements as $entitlement) {
            $entitlements[$entitlement->entitlement_key] = $this->cast($entitlement->value_type, $entitlement->value);
        }

        $overrides = TenantEntitlementOverride::query()
            ->where('tenant_id', $tenantId)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderBy('id')
            ->get();

        foreach ($overrides as $override) {
            $entitlements[$override->entitlement_key] = $this->cast($override->value_type, $override->value);
        }

        if (! $subscription->hasFullAccess()) {
            $entitlements = $this->restrict($entitlements);
        }

        return ['subscribed' => true, 'entitlements' => $entitlements];
    }

    /**
     * @param  array<string, bool|int>  $entitlements
     * @return array<string, bool|int>
     */
    private function restrict(array $entitlements): array
    {
        $kept = config('entitlements.restricted_keeps');
        $kept = is_array($kept) ? $kept : [];

        $restricted = [];

        foreach ($entitlements as $key => $value) {
            $restricted[$key] = in_array($key, $kept, true) ? $value : (is_int($value) ? 0 : false);
        }

        return $restricted;
    }

    private function cast(string $type, string $value): bool|int
    {
        return $type === PlanEntitlement::TYPE_INTEGER_LIMIT
            ? (int) $value
            : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
