<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Credit-weighted housekeeping work item. Checkout cleans close only
 * through inspection; low scores reopen as failed_inspection with a
 * fresh task.
 *
 * @property int $id
 * @property int $branch_id
 * @property int $room_id
 * @property string $kind
 * @property int $credits
 * @property int|null $assignee_id
 * @property string $status
 * @property int|null $inspection_score
 * @property array<int, string>|null $photo_paths
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read Room $room
 * @property-read User|null $assignee
 */
#[Fillable([
    'branch_id',
    'room_id',
    'kind',
    'credits',
    'assignee_id',
    'status',
    'inspection_score',
    'photo_paths',
])]
class HousekeepingTask extends Model
{
    public const KIND_CHECKOUT_CLEAN = 'checkout_clean';

    public const KIND_STAYOVER = 'stayover';

    public const KIND_TURNDOWN = 'turndown';

    public const KIND_INSPECTION = 'inspection';

    public const KIND_MINIBAR_CHECK = 'minibar_check';

    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED_INSPECTION = 'failed_inspection';

    protected function casts(): array
    {
        return [
            'credits' => 'integer',
            'inspection_score' => 'integer',
            'photo_paths' => 'array',
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

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
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
        return $query->whereIn('status', [self::STATUS_OPEN, self::STATUS_IN_PROGRESS]);
    }

    public function inspectable(): bool
    {
        return in_array($this->kind, [self::KIND_CHECKOUT_CLEAN, self::KIND_INSPECTION], true);
    }
}
