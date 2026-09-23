<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Data-subject request: access | erasure | portability. Fulfillment
 * bundles (access/portability) or purge receipts (erasure) land in
 * result; every transition is activity-logged with its actor.
 *
 * @property int $id
 * @property int|null $branch_id
 * @property int $guest_id
 * @property string $kind
 * @property string $status
 * @property array<string, mixed>|null $result
 * @property int|null $requested_by
 * @property int|null $fulfilled_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch|null $branch
 * @property-read Guest $guest
 */
#[Fillable([
    'branch_id',
    'guest_id',
    'kind',
    'status',
    'result',
    'requested_by',
    'fulfilled_by',
])]
class DsarRequest extends Model
{
    public const KIND_ACCESS = 'access';

    public const KIND_ERASURE = 'erasure';

    public const KIND_PORTABILITY = 'portability';

    public const STATUS_OPEN = 'open';

    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUS_REJECTED = 'rejected';

    protected function casts(): array
    {
        return [
            'result' => 'array',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Guest, $this> */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }
}
