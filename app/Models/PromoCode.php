<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Stay-discount code with a capped redemption count. uses_count is only
 * ever incremented under row lock inside the booking transaction, so the
 * cap cannot be overshot by concurrent bookings.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $code
 * @property int|null $discount_bps
 * @property int|null $discount_fixed_minor
 * @property int|null $max_uses
 * @property int $uses_count
 * @property int $min_nights
 * @property Carbon $valid_from
 * @property Carbon|null $valid_to
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 */
#[Fillable([
    'branch_id',
    'code',
    'discount_bps',
    'discount_fixed_minor',
    'min_nights',
    'max_uses',
    'valid_from',
    'valid_to',
    'is_active',
])]
class PromoCode extends Model
{
    protected function casts(): array
    {
        return [
            'discount_bps' => 'integer',
            'discount_fixed_minor' => 'integer',
            'max_uses' => 'integer',
            'uses_count' => 'integer',
            'min_nights' => 'integer',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
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

    public function exhausted(): bool
    {
        return $this->max_uses !== null && $this->uses_count >= $this->max_uses;
    }
}
