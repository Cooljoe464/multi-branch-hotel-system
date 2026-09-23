<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Minibar consumption posted once to the guest folio (or the room's
 * current reservation folio). Idempotent on idempotency_key.
 *
 * @property int $id
 * @property int $branch_id
 * @property int $room_id
 * @property int|null $folio_id
 * @property int|null $reservation_id
 * @property array<int, array<string, mixed>> $items
 * @property int $total_minor
 * @property string $idempotency_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read Room $room
 * @property-read Folio|null $folio
 * @property-read Reservation|null $reservation
 */
#[Fillable([
    'branch_id',
    'room_id',
    'folio_id',
    'reservation_id',
    'items',
    'total_minor',
    'idempotency_key',
])]
class MinibarPosting extends Model
{
    protected function casts(): array
    {
        return [
            'items' => 'array',
            'total_minor' => 'integer',
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

    /** @return BelongsTo<Folio, $this> */
    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
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
