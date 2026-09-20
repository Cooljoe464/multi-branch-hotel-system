<?php

namespace App\Models;

use Database\Factories\YieldRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $branch_id
 * @property int|null $room_type_id
 * @property int $min_occupancy_pct
 * @property int $max_occupancy_pct
 * @property float $rate_multiplier
 * @property int|null $mlos_override
 * @property bool|null $cta_override
 * @property bool $is_active
 * @property int $priority
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read RoomType|null $roomType
 */
#[Fillable([
    'branch_id',
    'room_type_id',
    'min_occupancy_pct',
    'max_occupancy_pct',
    'rate_multiplier',
    'mlos_override',
    'cta_override',
    'is_active',
    'priority',
])]
class YieldRule extends Model
{
    /** @use HasFactory<YieldRuleFactory> */
    use HasFactory;

    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'min_occupancy_pct' => 'integer',
            'max_occupancy_pct' => 'integer',
            'rate_multiplier' => 'float',
            'mlos_override' => 'integer',
            'cta_override' => 'boolean',
            'is_active' => 'boolean',
            'priority' => 'integer',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
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

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForRoomType(Builder $query, ?int $roomTypeId): Builder
    {
        return $query->where(function ($q) use ($roomTypeId) {
            $q->whereNull('room_type_id')
                ->orWhere('room_type_id', $roomTypeId);
        });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeMatchingOccupancy(Builder $query, int $occupancyPct): Builder
    {
        return $query->where('min_occupancy_pct', '<=', $occupancyPct)
            ->where('max_occupancy_pct', '>=', $occupancyPct);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOrderedByPriority(Builder $query): Builder
    {
        return $query->orderByDesc('priority');
    }
}
