<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Daily FX reference rate. Integers only: 1 unit of base buys
 * rate_micro / 1_000_000 units of quote. Same-pair re-rates for a
 * date overwrite; history stays for audit.
 *
 * @property int $id
 * @property string $base_code
 * @property string $quote_code
 * @property Carbon $rate_date
 * @property int $rate_micro
 * @property string $source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'base_code',
    'quote_code',
    'rate_date',
    'rate_micro',
    'source',
])]
class FxRate extends Model
{
    protected function casts(): array
    {
        return [
            'rate_date' => 'date',
        ];
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForPair(Builder $query, string $base, string $quote): Builder
    {
        return $query->where('base_code', strtoupper($base))->where('quote_code', strtoupper($quote));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOnOrBefore(Builder $query, string $date): Builder
    {
        return $query->where('rate_date', '<=', $date);
    }
}
