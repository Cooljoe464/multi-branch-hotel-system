<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Daily housekeeping plan. Assignments map room ids to
 * {attendant_id, kind, credits, pinned}; re-runs regenerate
 * everything except pinned rows, and publishing materializes
 * HousekeepingTask rows idempotently.
 *
 * @property int $id
 * @property int $branch_id
 * @property Carbon $work_date
 * @property array<int, array{attendant_id: int|null, kind: string, credits: int, pinned?: bool}>|null $assignments
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 */
#[Fillable([
    'branch_id',
    'work_date',
    'assignments',
    'status',
])]
class HkSchedule extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'assignments' => 'array',
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
