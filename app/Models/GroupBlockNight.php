<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Held nights for one block + room type + stay date. `blocked` is the
 * hold count, `picked_up` how many converted to reservations.
 *
 * @property int $id
 * @property int $group_block_id
 * @property int $room_type_id
 * @property Carbon $stay_date
 * @property int $blocked
 * @property int $picked_up
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read GroupBlock $block
 * @property-read RoomType $roomType
 */
#[Fillable([
    'group_block_id',
    'room_type_id',
    'stay_date',
    'blocked',
    'picked_up',
])]
class GroupBlockNight extends Model
{
    protected function casts(): array
    {
        return [
            'stay_date' => 'date',
            'blocked' => 'integer',
            'picked_up' => 'integer',
        ];
    }

    /** @return BelongsTo<GroupBlock, $this> */
    public function block(): BelongsTo
    {
        return $this->belongsTo(GroupBlock::class, 'group_block_id');
    }

    /** @return BelongsTo<RoomType, $this> */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    public function remaining(): int
    {
        return max(0, $this->blocked - $this->picked_up);
    }
}
