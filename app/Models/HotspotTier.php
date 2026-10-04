<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Priced Wi-Fi tier per branch. Free tier (price 0) is auto-included;
 * paid tiers post to the folio before/at reservation time.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $name
 * @property string $code
 * @property int $price_minor
 * @property int $rate_up_kbps
 * @property int $rate_down_kbps
 * @property int|null $quota_mb
 * @property int|null $duration_mins
 * @property int $device_limit
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 */
#[Fillable([
    'branch_id',
    'name',
    'code',
    'price_minor',
    'rate_up_kbps',
    'rate_down_kbps',
    'quota_mb',
    'duration_mins',
    'device_limit',
    'is_active',
])]
class HotspotTier extends Model
{
    public const CODE_FREE = 'free';

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'rate_up_kbps' => 'integer',
            'rate_down_kbps' => 'integer',
            'quota_mb' => 'integer',
            'duration_mins' => 'integer',
            'device_limit' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<WifiSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(WifiSession::class, 'hotspot_tier_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isFree(): bool
    {
        return $this->price_minor <= 0 || $this->code === self::CODE_FREE;
    }

    /**
     * MikroTik rate-limit string: "rx/tx" in bps-ish notation.
     * RouterOS accepts e.g. "2M/4M".
     */
    public function mikrotikRateLimit(): string
    {
        $up = max(64, $this->rate_up_kbps).'k';
        $down = max(64, $this->rate_down_kbps).'k';

        return "{$up}/{$down}";
    }
}
