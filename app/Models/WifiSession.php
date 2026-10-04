<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $branch_id
 * @property int|null $reservation_id
 * @property int|null $hotspot_tier_id
 * @property string $voucher
 * @property string|null $username
 * @property string|null $password
 * @property int|null $folio_transaction_id
 * @property int $bytes_used
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $provisioned_at
 * @property Carbon|null $deprovisioned_at
 * @property Carbon|null $last_acct_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read Reservation|null $reservation
 * @property-read HotspotTier|null $tier
 */
#[Fillable([
    'branch_id',
    'reservation_id',
    'hotspot_tier_id',
    'voucher',
    'username',
    'password',
    'folio_transaction_id',
    'bytes_used',
    'expires_at',
    'revoked_at',
    'provisioned_at',
    'deprovisioned_at',
    'last_acct_at',
])]
class WifiSession extends Model
{
    protected function casts(): array
    {
        return [
            'bytes_used' => 'integer',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'provisioned_at' => 'datetime',
            'deprovisioned_at' => 'datetime',
            'last_acct_at' => 'datetime',
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

    /** @return BelongsTo<HotspotTier, $this> */
    public function tier(): BelongsTo
    {
        return $this->belongsTo(HotspotTier::class, 'hotspot_tier_id');
    }

    public function usable(): bool
    {
        return $this->revoked_at === null
            && $this->deprovisioned_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
