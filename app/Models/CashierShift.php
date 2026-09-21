<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $branch_id
 * @property int $user_id
 * @property Carbon $business_date
 * @property int $opening_float_minor
 * @property int $expected_cash_minor
 * @property int|null $counted_cash_minor
 * @property int|null $variance_minor
 * @property string $status
 * @property Carbon|null $opened_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read User $cashier
 */
#[Fillable([
    'branch_id',
    'user_id',
    'business_date',
    'opening_float_minor',
    'expected_cash_minor',
    'counted_cash_minor',
    'variance_minor',
    'status',
    'opened_at',
    'closed_at',
])]
class CashierShift extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_RECONCILED = 'reconciled';

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'opening_float_minor' => 'integer',
            'expected_cash_minor' => 'integer',
            'counted_cash_minor' => 'integer',
            'variance_minor' => 'integer',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }
}
