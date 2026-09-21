<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Monthly room-night and revenue targets per property. Month is stored
 * as the first of the month; OTB + actuals compare against it.
 *
 * @property int $id
 * @property int $branch_id
 * @property Carbon $month
 * @property int $room_nights_target
 * @property int $revenue_target_minor
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 */
#[Fillable([
    'branch_id',
    'month',
    'room_nights_target',
    'revenue_target_minor',
])]
class Budget extends Model
{
    protected function casts(): array
    {
        return [
            'month' => 'date',
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
}
