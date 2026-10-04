<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Tier chosen for a reservation before it completes. Fulfilled into a
 * wifi_session on check-in (auto-provision).
 *
 * @property int $id
 * @property int $reservation_id
 * @property int|null $hotspot_tier_id
 * @property int $fee_minor
 * @property string|null $voucher
 * @property int|null $folio_transaction_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Reservation $reservation
 * @property-read HotspotTier|null $tier
 */
#[Fillable([
    'reservation_id',
    'hotspot_tier_id',
    'fee_minor',
    'voucher',
    'folio_transaction_id',
])]
class ReservationHotspot extends Model
{
    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** @return BelongsTo<HotspotTier, $this> */
    public function tier(): BelongsTo
    {
        return $this->belongsTo(HotspotTier::class, 'hotspot_tier_id');
    }
}
