<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Append-only points movement. Corrections are compensating rows,
 * never edits — same discipline as the financial journal.
 *
 * @property int $id
 * @property int $loyalty_account_id
 * @property int $delta
 * @property string $reason
 * @property string|null $source_type
 * @property int|null $source_id
 * @property string $idempotency_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read LoyaltyAccount $account
 * @property-read Model|null $source
 */
#[Fillable([
    'loyalty_account_id',
    'delta',
    'reason',
    'source_type',
    'source_id',
    'idempotency_key',
])]
class LoyaltyLedger extends Model
{
    protected $table = 'loyalty_ledger';

    protected function casts(): array
    {
        return [
            'delta' => 'integer',
        ];
    }

    /** @return BelongsTo<LoyaltyAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LoyaltyAccount::class, 'loyalty_account_id');
    }

    /** @return MorphTo<Model, $this> */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
