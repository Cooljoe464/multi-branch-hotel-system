<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Models\ApiKeyAuditLog;
use App\Models\Branch;
use App\Models\Tenant;
use App\Support\TenantDatabaseContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PublicApiKeyService
{
    public function __construct(
        private readonly TenantDatabaseContext $tenantContext,
        private readonly EntitlementService $entitlements,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{key: ApiKey, secret: string}
     */
    public function issue(Branch $branch, array $attributes = []): array
    {
        $environment = $this->stringValue($attributes['environment'] ?? null, 'live');
        $type = $this->stringValue($attributes['type'] ?? null, 'publishable');

        if (! in_array($environment, ['test', 'live'], true) || ! in_array($type, ['publishable', 'secret'], true)) {
            throw new DomainException('Public API keys require a valid type and environment.');
        }

        if ($type === 'secret' && ! empty($attributes['allowed_origins'])) {
            throw new DomainException('Secret API keys cannot be configured for browser origins.');
        }

        $requestedScopes = $attributes['scopes'] ?? [];
        if (! is_array($requestedScopes)) {
            throw new DomainException('Public API key scopes must be a list of strings.');
        }

        $scopes = [];
        foreach ($requestedScopes as $scope) {
            if (! is_string($scope)) {
                throw new DomainException('Public API key scopes must be a list of strings.');
            }

            $scopes[] = $scope;
        }

        $attributes['scopes'] = $scopes;

        if ($type === 'publishable'
            && array_diff($scopes, ['availability.view', 'holds.create', 'holds.release']) !== []) {
            throw new DomainException('Publishable API keys may only read availability and manage inventory holds.');
        }

        $result = $this->tenantContext->run(null, $branch->tenant_id, function () use ($branch, $attributes, $environment, $type): array {
            $this->tenantContext->setBranchId($branch->id);

            if (! $this->entitlements->allows($branch->tenant_id, 'api.public')) {
                throw new DomainException('The public API entitlement is not active for this property.');
            }

            return DB::transaction(function () use ($branch, $attributes, $environment, $type): array {
                Tenant::query()->whereKey($branch->tenant_id)->lockForUpdate()->firstOrFail();
                $keyLimit = $this->entitlements->limit($branch->tenant_id, 'limits.api.keys.max');

                if ($keyLimit !== null && ApiKey::query()->where('tenant_id', $branch->tenant_id)->whereNull('revoked_at')->count() >= $keyLimit) {
                    throw new DomainException('The public API key limit has been reached.');
                }

                $secret = Str::random(48);
                $prefix = ($type === 'secret' ? 'sk_' : 'pk_').$environment.'_';
                $plainTextKey = $prefix.$secret;
                $key = ApiKey::create([
                    'tenant_id' => $branch->tenant_id,
                    'branch_id' => $branch->id,
                    'name' => $this->stringValue($attributes['name'] ?? null, 'Public API key'),
                    'type' => $type,
                    'environment' => $environment,
                    'prefix' => $prefix,
                    'key_hash' => hash('sha256', $plainTextKey),
                    'allowed_origins' => $attributes['allowed_origins'] ?? null,
                    'ip_allowlist' => $attributes['ip_allowlist'] ?? null,
                    'scopes' => $attributes['scopes'],
                    'hold_limit_per_key' => $this->intValue($attributes['hold_limit_per_key'] ?? null, 100),
                    'hold_limit_per_ip' => $this->intValue($attributes['hold_limit_per_ip'] ?? null, 20),
                    'hold_limit_per_session' => $this->intValue($attributes['hold_limit_per_session'] ?? null, 5),
                    'created_by' => $attributes['created_by'] ?? null,
                ]);

                $this->audit($key, 'created', $attributes['created_by'] ?? null, [
                    'type' => $type,
                    'environment' => $environment,
                    'prefix' => $prefix,
                ]);

                return ['key' => $key, 'secret' => $plainTextKey];
            }, 3);
        }, actorType: 'api_key_manager');

        if (! is_array($result) || ! ($result['key'] ?? null) instanceof ApiKey || ! is_string($result['secret'] ?? null)) {
            throw new \LogicException('The API key issuance operation returned an invalid result.');
        }

        return ['key' => $result['key'], 'secret' => $result['secret']];
    }

    public function resolve(string $plainTextKey): ?ApiKey
    {
        $hash = hash('sha256', $plainTextKey);

        return DB::transaction(function () use ($hash): ?ApiKey {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::select('select set_config(?, ?, true)', ['app.api_key_hash', $hash]);
            }

            return ApiKey::query()
                ->where(function ($query) use ($hash): void {
                    $query->where('key_hash', $hash)->orWhere('previous_key_hash', $hash);
                })
                ->whereNull('revoked_at')
                ->first();
        });
    }

    /** @return array{key: ApiKey, secret: string, grace_until: string} */
    public function rotate(ApiKey $key, int $graceHours = 24, ?int $actorId = null): array
    {
        $result = $this->tenantContext->run(null, $key->tenant_id, function () use ($key, $graceHours, $actorId): array {
            $this->tenantContext->setBranchId($key->branch_id);

            return DB::transaction(function () use ($key, $graceHours, $actorId): array {
                $locked = ApiKey::query()->whereKey($key->id)->lockForUpdate()->firstOrFail();
                if (! $locked->usable()) {
                    throw new DomainException('A revoked API key cannot be rotated.');
                }

                $plainTextSecret = $locked->prefix.Str::random(48);
                $graceUntil = now()->addHours(max(1, $graceHours));
                $locked->update([
                    'previous_key_hash' => $locked->key_hash,
                    'previous_valid_until' => $graceUntil,
                    'key_hash' => hash('sha256', $plainTextSecret),
                ]);
                $this->audit($locked, 'rotated', $actorId, ['grace_until' => $graceUntil->toIso8601String()]);

                return [
                    'key' => $locked->fresh() ?? $locked,
                    'secret' => $plainTextSecret,
                    'grace_until' => $graceUntil->toIso8601String(),
                ];
            }, 3);
        }, actorType: 'api_key_manager');

        if (! is_array($result) || ! ($result['key'] ?? null) instanceof ApiKey || ! is_string($result['secret'] ?? null) || ! is_string($result['grace_until'] ?? null)) {
            throw new \LogicException('The API key rotation operation returned an invalid result.');
        }

        return ['key' => $result['key'], 'secret' => $result['secret'], 'grace_until' => $result['grace_until']];
    }

    public function revoke(ApiKey $key, ?int $actorId = null): ApiKey
    {
        $result = $this->tenantContext->run(null, $key->tenant_id, function () use ($key, $actorId): ApiKey {
            $this->tenantContext->setBranchId($key->branch_id);

            return DB::transaction(function () use ($key, $actorId): ApiKey {
                $locked = ApiKey::query()->whereKey($key->id)->lockForUpdate()->firstOrFail();

                if ($locked->revoked_at !== null) {
                    return $locked;
                }

                $locked->update([
                    'revoked_at' => now(),
                    'previous_key_hash' => null,
                    'previous_valid_until' => null,
                ]);
                $this->audit($locked, 'revoked', $actorId);

                return $locked->fresh() ?? $locked;
            }, 3);
        }, actorType: 'api_key_manager');

        if (! $result instanceof ApiKey) {
            throw new \LogicException('The API key revocation operation returned an invalid result.');
        }

        return $result;
    }

    private function stringValue(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function intValue(mixed $value, int $default): int
    {
        return is_int($value) ? $value : $default;
    }

    /** @param array<string, mixed> $metadata */
    private function audit(ApiKey $key, string $event, mixed $actorId, array $metadata = []): void
    {
        ApiKeyAuditLog::create([
            'tenant_id' => $key->tenant_id,
            'branch_id' => $key->branch_id,
            'api_key_id' => $key->id,
            'actor_id' => is_int($actorId) ? $actorId : null,
            'event' => $event,
            'metadata' => $metadata,
        ]);
    }
}
