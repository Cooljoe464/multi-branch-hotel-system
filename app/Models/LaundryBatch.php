<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $branch_id
 * @property string $batch_number
 * @property string $vendor_type
 * @property string|null $vendor_name
 * @property string $status
 * @property array<int, int> $item_counts
 * @property Carbon $dispatched_at
 * @property Carbon|null $returned_at
 * @property int $dispatched_by
 * @property int|null $received_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read User $dispatcher
 * @property-read User|null $receiver
 */
#[Fillable([
    'branch_id',
    'batch_number',
    'vendor_type',
    'vendor_name',
    'status',
    'item_counts',
    'dispatched_at',
    'returned_at',
    'dispatched_by',
    'received_by',
])]
class LaundryBatch extends Model
{
    use HasFactory;

    public const STATUS_SENT = 'sent';

    public const STATUS_IN_WASH = 'in_wash';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_RECONCILED = 'reconciled';

    protected function casts(): array
    {
        return [
            'item_counts' => 'array',
            'dispatched_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function dispatcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    /** @return BelongsTo<User, $this> */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
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
    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_SENT, self::STATUS_IN_WASH]);
    }
}
