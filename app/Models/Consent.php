<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Consent ledger: one row per guest × channel × purpose, latest wins
 * by timestamp. Every outbound sender must consult it; no consent
 * means no send, logged as a skip.
 *
 * @property int $id
 * @property int $guest_id
 * @property string $channel
 * @property string $purpose
 * @property bool $granted
 * @property Carbon|null $at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Guest $guest
 */
#[Fillable([
    'guest_id',
    'channel',
    'purpose',
    'granted',
    'at',
])]
class Consent extends Model
{
    protected function casts(): array
    {
        return [
            'granted' => 'boolean',
            'at' => 'datetime',
        ];
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
    public function scopeForGuest(Builder $query, int $guestId): Builder
    {
        return $query->where('guest_id', $guestId);
    }

    public static function granted(int $guestId, string $channel, string $purpose): bool
    {
        $latest = static::forGuest($guestId)
            ->where('channel', $channel)
            ->where('purpose', $purpose)
            ->orderByDesc('at')
            ->orderByDesc('id')
            ->first();

        return $latest !== null && $latest->granted;
    }
}
