<?php

namespace App\Models;

use Database\Factories\CityLedgerTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $city_ledger_account_id
 * @property int|null $folio_id
 * @property string $type
 * @property int $amount
 * @property string|null $reference
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CityLedgerAccount $cityLedgerAccount
 * @property-read Folio|null $folio
 */
#[Fillable([
    'city_ledger_account_id',
    'currency_code',
    'folio_id',
    'type',
    'amount',
    'reference',
    'notes',
])]
class CityLedgerTransaction extends Model
{
    /** @use HasFactory<CityLedgerTransactionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
        ];
    }

    /** @return BelongsTo<CityLedgerAccount, $this> */
    public function cityLedgerAccount(): BelongsTo
    {
        return $this->belongsTo(CityLedgerAccount::class);
    }

    /** @return BelongsTo<Folio, $this> */
    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }
}
