<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One points wallet per guest. Balance moves only through ledger
 * rows; the column is a cached sum, never written directly by
 * callers (service adjusts it under lock alongside the row).
 *
 * @property int $id
 * @property int $guest_id
 * @property int $points
 * @property string $tier
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Guest $guest
 * @property-read Collection<int, LoyaltyLedger> $entries
 */
#[Fillable([
    'guest_id',
    'points',
    'tier',
])]
class LoyaltyAccount extends Model
{
    protected function casts(): array
    {
        return [
            'points' => 'integer',
        ];
    }

    /** @return BelongsTo<Guest, $this> */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    /** @return HasMany<LoyaltyLedger, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(LoyaltyLedger::class);
    }
}
