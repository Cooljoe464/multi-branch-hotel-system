<?php

namespace App\Models;

use Database\Factories\ApiKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Hashed public API credential scoped to one tenant and branch.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $branch_id
 * @property string $name
 * @property string $type
 * @property string $environment
 * @property string $prefix
 * @property string $key_hash
 * @property string|null $previous_key_hash
 * @property Carbon|null $previous_valid_until
 * @property list<string>|null $allowed_origins
 * @property list<string>|null $ip_allowlist
 * @property list<string> $scopes
 * @property int $hold_limit_per_key
 * @property int $hold_limit_per_ip
 * @property int $hold_limit_per_session
 * @property Carbon|null $revoked_at
 * @property Carbon|null $last_used_at
 */
#[Fillable([
    'tenant_id',
    'branch_id',
    'name',
    'type',
    'environment',
    'prefix',
    'key_hash',
    'previous_key_hash',
    'previous_valid_until',
    'allowed_origins',
    'ip_allowlist',
    'scopes',
    'hold_limit_per_key',
    'hold_limit_per_ip',
    'hold_limit_per_session',
    'revoked_at',
    'last_used_at',
    'created_by',
])]
class ApiKey extends Model
{
    /** @use HasFactory<ApiKeyFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'previous_valid_until' => 'datetime',
            'allowed_origins' => 'array',
            'ip_allowlist' => 'array',
            'scopes' => 'array',
            'hold_limit_per_key' => 'integer',
            'hold_limit_per_ip' => 'integer',
            'hold_limit_per_session' => 'integer',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<InventoryHold, $this> */
    public function holds(): HasMany
    {
        return $this->hasMany(InventoryHold::class);
    }

    /** @return HasMany<ApiKeyDailyUsage, $this> */
    public function dailyUsage(): HasMany
    {
        return $this->hasMany(ApiKeyDailyUsage::class);
    }

    public function acceptsHash(string $hash): bool
    {
        if (hash_equals($this->key_hash, $hash)) {
            return true;
        }

        return $this->previous_key_hash !== null
            && $this->previous_valid_until?->isFuture() === true
            && hash_equals($this->previous_key_hash, $hash);
    }

    public function usable(): bool
    {
        return $this->revoked_at === null;
    }
}
