<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One accepted upsell: frozen fee on the folio, idempotent on its key.
 *
 * @property int $id
 * @property int $reservation_id
 * @property int $upsell_offer_id
 * @property int $fee_minor
 * @property string $idempotency_key
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Reservation $reservation
 * @property-read UpsellOffer $offer
 */
#[Fillable([
    'reservation_id',
    'upsell_offer_id',
    'fee_minor',
    'idempotency_key',
    'expires_at',
])]
class UpsellAcceptance extends Model
{
    protected function casts(): array
    {
        return [
            'fee_minor' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** @return BelongsTo<UpsellOffer, $this> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(UpsellOffer::class, 'upsell_offer_id');
    }
}
