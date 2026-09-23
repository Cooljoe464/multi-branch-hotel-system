<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Dated out-of-order span. OOO nights leave sellable inventory
 * (total_rooms drops); out-of-service stays sellable with a flag.
 *
 * @property int $id
 * @property int $branch_id
 * @property int $room_id
 * @property Carbon $from_date
 * @property Carbon $to_date
 * @property string|null $reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read Room $room
 */
#[Fillable([
    'branch_id',
    'room_id',
    'from_date',
    'to_date',
    'reason',
])]
class RoomOut extends Model
{
    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
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
    public function scopeCovering(Builder $query, string $date): Builder
    {
        return $query->where('from_date', '<=', $date)->where('to_date', '>=', $date);
    }
}
