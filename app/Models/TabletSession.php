<?php

namespace App\Models;

use Database\Factories\TabletSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $branch_id
 * @property int $room_id
 * @property int $reservation_id
 * @property string $confirmation_number
 * @property string|null $device_id
 * @property Carbon|null $last_active_at
 * @property Carbon|null $wiped_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read Room $room
 * @property-read Reservation $reservation
 */
#[Fillable([
    'branch_id',
    'room_id',
    'reservation_id',
    'confirmation_number',
    'device_id',
    'last_active_at',
    'wiped_at',
    'metadata',
])]
class TabletSession extends Model
{
    /** @use HasFactory<TabletSessionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'last_active_at' => 'datetime',
            'wiped_at' => 'datetime',
            'metadata' => 'array',
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

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function wipe(): bool
    {
        return $this->update(['wiped_at' => now()]);
    }

    public function pair(Reservation $reservation): bool
    {
        return $this->update([
            'reservation_id' => $reservation->id,
            'confirmation_number' => $reservation->confirmation_number,
            'last_active_at' => now(),
            'wiped_at' => null,
        ]);
    }

    public function isStale(): bool
    {
        return $this->last_active_at !== null && $this->last_active_at->diffInMinutes(now()) > 30;
    }
}
