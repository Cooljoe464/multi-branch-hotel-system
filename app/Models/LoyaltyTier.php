<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Global tier ladder: lifetime nights unlock better earn rates.
 *
 * @property int $id
 * @property string $name
 * @property int $threshold_nights
 * @property int $earn_bps
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name',
    'threshold_nights',
    'earn_bps',
])]
class LoyaltyTier extends Model
{
    protected function casts(): array
    {
        return [
            'threshold_nights' => 'integer',
            'earn_bps' => 'integer',
        ];
    }
}
