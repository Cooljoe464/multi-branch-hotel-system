<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Recurring yearly season window (e.g. Detty December). The window may
 * wrap past New Year. Highest priority wins when windows overlap.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $name
 * @property string $code
 * @property int $start_month
 * @property int $start_day
 * @property int $end_month
 * @property int $end_day
 * @property int $multiplier_bps
 * @property int $priority
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 */
#[Fillable([
    'branch_id',
    'name',
    'code',
    'start_month',
    'start_day',
    'end_month',
    'end_day',
    'multiplier_bps',
    'priority',
    'is_active',
])]
class RateSeason extends Model
{
    protected function casts(): array
    {
        return [
            'start_month' => 'integer',
            'start_day' => 'integer',
            'end_month' => 'integer',
            'end_day' => 'integer',
            'multiplier_bps' => 'integer',
            'priority' => 'integer',
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

    /**
     * Does this recurring window contain the given month/day?
     */
    public function contains(int $month, int $day): bool
    {
        $start = $this->start_month * 100 + $this->start_day;
        $end = $this->end_month * 100 + $this->end_day;
        $value = $month * 100 + $day;

        return $start <= $end
            ? $value >= $start && $value <= $end
            : $value >= $start || $value <= $end;
    }
}
