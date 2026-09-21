<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Tokenized payment method. PANs are never stored — only gateway tokens.
 *
 * @property int $id
 * @property string|null $owner_type
 * @property int|null $owner_id
 * @property string $driver
 * @property string $token
 * @property string|null $brand
 * @property string|null $last4
 * @property Carbon|null $exp_date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'owner_type',
    'owner_id',
    'driver',
    'token',
    'brand',
    'last4',
    'exp_date',
])]
class PaymentMethod extends Model
{
    protected function casts(): array
    {
        return [
            'exp_date' => 'date',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function masked(): string
    {
        return ($this->brand ? $this->brand.' ' : '').'•••• '.($this->last4 ?? '••••');
    }
}
