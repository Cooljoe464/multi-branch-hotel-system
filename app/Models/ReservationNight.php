<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One consumed night of a reservation. The set of nights is the physical
 * hold: releasing a reservation deletes its nights and decrements sold.
 *
 * @property int $id
 * @property int $reservation_id
 * @property int|null $room_id
 * @property Carbon $stay_date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Reservation|null $reservation
 * @property-read Room|null $room
 */
#[Fillable([
    'reservation_id',
    'room_id',
    'stay_date',
])]
class ReservationNight extends Model
{
    protected function casts(): array
    {
        return [
            'stay_date' => 'date',
        ];
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForReservation(Builder $query, int $reservationId): Builder
    {
        return $query->where('reservation_id', $reservationId);
    }
}
