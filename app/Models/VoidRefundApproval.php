<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property int $reason_code_id
 * @property int $requested_by
 * @property int|null $approved_by
 * @property string $status
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read VoidRefundCode $reasonCode
 */
#[Fillable([
    'subject_type',
    'subject_id',
    'reason_code_id',
    'requested_by',
    'approved_by',
    'status',
    'note',
])]
class VoidRefundApproval extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<VoidRefundCode, $this> */
    public function reasonCode(): BelongsTo
    {
        return $this->belongsTo(VoidRefundCode::class, 'reason_code_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
