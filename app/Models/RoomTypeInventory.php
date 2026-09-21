<?php

namespace App\Models;

use Database\Factories\RoomTypeInventoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-night sell ledger for one room type. Single source of truth for
 * availability: every reservation consumes exactly one unit per night.
 *
 * @property int $id
 * @property int $branch_id
 * @property int $room_type_id
 * @property Carbon $stay_date
 * @property int $total_rooms
 * @property int $sold
 * @property int $blocked
 * @property int $overbooking_limit
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read RoomType $roomType
 */
#[Fillable([
    'branch_id',
    'room_type_id',
    'stay_date',
    'total_rooms',
    'sold',
    'blocked',
    'overbooking_limit',
])]
class RoomTypeInventory extends Model
{
    /** @use HasFactory<RoomTypeInventoryFactory> */
    use HasFactory;

    protected $table = 'room_type_inventory';

    protected function casts(): array
    {
        return [
            'stay_date' => 'date',
            'total_rooms' => 'integer',
            'sold' => 'integer',
            'blocked' => 'integer',
            'overbooking_limit' => 'integer',
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

    public function sellable(): int
    {
        return $this->total_rooms + $this->overbooking_limit - $this->blocked - $this->sold;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForRoomType(Builder $query, int $roomTypeId): Builder
    {
        return $query->where('room_type_id', $roomTypeId);
    }
}
