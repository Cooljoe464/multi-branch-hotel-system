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
 * @property int $rate_plan_id
 * @property int|null $room_type_id
 * @property Carbon $stay_date
 * @property int|null $min_los
 * @property int|null $max_los
 * @property bool $cta
 * @property bool $ctd
 * @property bool $stop_sell
 * @property int|null $min_advance_hours
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read RatePlan $ratePlan
 * @property-read RoomType|null $roomType
 */
#[Fillable([
    'branch_id',
    'rate_plan_id',
    'room_type_id',
    'stay_date',
    'min_los',
    'max_los',
    'cta',
    'ctd',
    'stop_sell',
    'min_advance_hours',
])]
class RateRestriction extends Model
{
    protected function casts(): array
    {
        return [
            'stay_date' => 'date',
            'min_los' => 'integer',
            'max_los' => 'integer',
            'cta' => 'boolean',
            'ctd' => 'boolean',
            'stop_sell' => 'boolean',
            'min_advance_hours' => 'integer',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<RatePlan, $this> */
    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class);
    }

    /** @return BelongsTo<RoomType, $this> */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
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
