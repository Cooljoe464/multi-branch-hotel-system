<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Audited mid-stay room move. Folio charges are untouched by moves
 * (they live on folio windows, not rooms); past nights keep the old
 * room, future nights point at the new one.
 *
 * @property int $id
 * @property int $branch_id
 * @property int $reservation_id
 * @property int|null $from_room_id
 * @property int $to_room_id
 * @property Carbon $moved_at
 * @property int|null $moved_by
 * @property string|null $reason
 * @property bool $key_reissued
 * @property string|null $idempotency_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read Reservation $reservation
 * @property-read Room|null $fromRoom
 * @property-read Room $toRoom
 * @property-read User|null $mover
 */
#[Fillable([
    'branch_id',
    'reservation_id',
    'from_room_id',
    'to_room_id',
    'moved_at',
    'moved_by',
    'reason',
    'key_reissued',
    'idempotency_key',
])]
class ReservationMove extends Model
{
    protected function casts(): array
    {
        return [
            'moved_at' => 'datetime',
            'key_reissued' => 'boolean',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** @return BelongsTo<Room, $this> */
    public function fromRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'from_room_id');
    }

    /** @return BelongsTo<Room, $this> */
    public function toRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'to_room_id');
    }

    /** @return BelongsTo<User, $this> */
    public function mover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moved_by');
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
