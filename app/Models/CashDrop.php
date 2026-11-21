<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $cashier_shift_id
 * @property int $amount_minor
 * @property string $envelope_number
 * @property int|null $witness_user_id
 * @property Carbon $dropped_at
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CashierShift $shift
 * @property-read User|null $witness
 */
#[Fillable([
    'cashier_shift_id',
    'amount_minor',
    'envelope_number',
    'witness_user_id',
    'dropped_at',
    'notes',
])]
class CashDrop extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'dropped_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CashierShift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id');
    }

    /** @return BelongsTo<User, $this> */
    public function witness(): BelongsTo
    {
        return $this->belongsTo(User::class, 'witness_user_id');
    }
}
