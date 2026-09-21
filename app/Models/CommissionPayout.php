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
 * A payout links whole accruals; the payout total must equal the sum
 * of its lines, so partial and over-payments are structurally
 * impossible. Paying clears the commission payable in the journal.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $source
 * @property int $amount_minor
 * @property string $status
 * @property string|null $reference
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read Collection<int, CommissionAccrual> $accruals
 */
#[Fillable([
    'branch_id',
    'source',
    'amount_minor',
    'status',
    'reference',
])]
class CommissionPayout extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsToMany<CommissionAccrual, $this> */
    public function accruals(): BelongsToMany
    {
        return $this->belongsToMany(CommissionAccrual::class, 'commission_accrual_payout');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }
}
