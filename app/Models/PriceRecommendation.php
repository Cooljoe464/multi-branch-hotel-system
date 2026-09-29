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
 * @property int $room_type_id
 * @property Carbon $stay_date
 * @property int $recommended_minor
 * @property int $current_minor
 * @property string $status
 * @property int|null $decided_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read RoomType $roomType
 * @property-read User|null $decider
 */
#[Fillable([
    'branch_id',
    'room_type_id',
    'stay_date',
    'recommended_minor',
    'current_minor',
    'status',
    'decided_by',
])]
class PriceRecommendation extends Model
{
    public const STATUS_PROPOSED = 'proposed';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_EXPIRED = 'expired';

    protected function casts(): array
    {
        return [
            'stay_date' => 'date',
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

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
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
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PROPOSED, self::STATUS_APPROVED]);
    }

    public function deviationBps(): int
    {
        if ($this->current_minor <= 0) {
            return 0;
        }

        return (int) round(($this->recommended_minor - $this->current_minor) * 10000 / $this->current_minor);
    }
}
