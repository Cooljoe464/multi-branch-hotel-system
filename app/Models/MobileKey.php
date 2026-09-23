<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * BLE mobile credential. Validity follows the stay (extended on
 * room moves, revoked on checkout); the secret itself is encrypted.
 *
 * @property int $id
 * @property int $reservation_id
 * @property string $device_id
 * @property string|null $key_enc
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_to
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Reservation $reservation
 */
#[Fillable([
    'reservation_id',
    'device_id',
    'key_enc',
    'valid_from',
    'valid_to',
    'status',
])]
class MobileKey extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_EXPIRED = 'expired';

    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
        ];
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
