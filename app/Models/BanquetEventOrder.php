<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Banquet event order: agreed spend for a function space on a date,
 * optionally linked to a group block. Charges post to the master
 * folio's banquet window.
 *
 * @property int $id
 * @property int $branch_id
 * @property int|null $group_block_id
 * @property int $function_space_id
 * @property Carbon $event_date
 * @property array<string, mixed>|null $schedule
 * @property int $agreed_total_minor
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read GroupBlock|null $block
 * @property-read FunctionSpace $functionSpace
 */
#[Fillable([
    'branch_id',
    'group_block_id',
    'function_space_id',
    'event_date',
    'schedule',
    'agreed_total_minor',
    'status',
])]
class BanquetEventOrder extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_POSTED = 'posted';

    public const STATUS_CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'schedule' => 'array',
            'agreed_total_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<GroupBlock, $this> */
    public function block(): BelongsTo
    {
        return $this->belongsTo(GroupBlock::class, 'group_block_id');
    }

    /** @return BelongsTo<FunctionSpace, $this> */
    public function functionSpace(): BelongsTo
    {
        return $this->belongsTo(FunctionSpace::class);
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
