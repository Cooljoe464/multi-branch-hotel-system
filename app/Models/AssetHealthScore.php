<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Daily failure probability per asset. Probability is a float by
 * design (not money); signals explain the score for the UI.
 *
 * @property int $id
 * @property int $asset_id
 * @property Carbon $scored_on
 * @property float $failure_prob
 * @property array<string, mixed>|null $signals
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Asset $asset
 */
#[Fillable([
    'asset_id',
    'scored_on',
    'failure_prob',
    'signals',
])]
class AssetHealthScore extends Model
{
    protected function casts(): array
    {
        return [
            'scored_on' => 'date',
            'failure_prob' => 'float',
            'signals' => 'array',
        ];
    }

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeRisky(Builder $query, float $threshold): Builder
    {
        return $query->where('failure_prob', '>=', $threshold);
    }
}
