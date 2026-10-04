<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One leg of a split transaction. Legs always sum to the parent amount;
 * the remainder goes to the first leg (documented, deterministic).
 *
 * @property int $id
 * @property int $transaction_id
 * @property Carbon|null $business_date
 * @property int $target_window_id
 * @property int $amount_minor
 * @property int|null $percent_bps
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Transaction $transaction
 * @property-read FolioWindow $targetWindow
 */
#[Fillable([
    'transaction_id',
    'business_date',
    'target_window_id',
    'amount_minor',
    'percent_bps',
])]
class TransactionSplit extends Model
{
    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'amount_minor' => 'integer',
            'percent_bps' => 'integer',
        ];
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /** @return BelongsTo<FolioWindow, $this> */
    public function targetWindow(): BelongsTo
    {
        return $this->belongsTo(FolioWindow::class, 'target_window_id');
    }
}
