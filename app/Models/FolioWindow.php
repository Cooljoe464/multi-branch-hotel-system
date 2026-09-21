<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A payer window inside a folio (room, incidentals, package...).
 * Charges route to windows; windows carry the payer.
 *
 * @property int $id
 * @property int $folio_id
 * @property string $code
 * @property string $payer_type
 * @property int|null $city_ledger_account_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Folio $folio
 */
#[Fillable([
    'folio_id',
    'code',
    'payer_type',
    'city_ledger_account_id',
])]
class FolioWindow extends Model
{
    public const CODE_ROOM = 'room';

    public const CODE_INCIDENTALS = 'incidentals';

    /** @return BelongsTo<Folio, $this> */
    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForFolio(Builder $query, int $folioId): Builder
    {
        return $query->where('folio_id', $folioId);
    }
}
