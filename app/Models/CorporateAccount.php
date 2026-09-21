<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Company account with a negotiated plan (or a flat bps discount) whose
 * stays settle to the city ledger.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $name
 * @property string $code
 * @property int|null $negotiated_plan_id
 * @property int $discount_bps
 * @property string|null $ledger_account
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read RatePlan|null $negotiatedPlan
 */
#[Fillable([
    'branch_id',
    'name',
    'code',
    'negotiated_plan_id',
    'discount_bps',
    'ledger_account',
    'is_active',
])]
class CorporateAccount extends Model
{
    protected function casts(): array
    {
        return [
            'negotiated_plan_id' => 'integer',
            'discount_bps' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<RatePlan, $this> */
    public function negotiatedPlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class, 'negotiated_plan_id');
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
}
