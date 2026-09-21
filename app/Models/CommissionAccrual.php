<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * One commission line per reservation, frozen at checkout from the
 * rate snapshot. Disputed lines are excluded from payouts until
 * resolved.
 *
 * @property int $id
 * @property int $branch_id
 * @property int $reservation_id
 * @property string $source
 * @property int $base_minor
 * @property int $amount_minor
 * @property string $status
 * @property string $idempotency_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read Reservation $reservation
 * @property-read Collection<int, CommissionPayout> $payouts
 */
#[Fillable([
    'branch_id',
    'reservation_id',
    'source',
    'base_minor',
    'amount_minor',
    'status',
    'idempotency_key',
])]
class CommissionAccrual extends Model
{
    public const STATUS_ACCRUED = 'accrued';

    public const STATUS_INVOICED = 'invoiced';

    public const STATUS_PAID = 'paid';

    public const STATUS_DISPUTED = 'disputed';

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** @return BelongsToMany<CommissionPayout, $this> */
    public function payouts(): BelongsToMany
    {
        return $this->belongsToMany(CommissionPayout::class, 'commission_accrual_payout');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }

    public function payable(): bool
    {
        return $this->status === self::STATUS_ACCRUED;
    }
}
