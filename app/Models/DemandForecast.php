<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Probability output is a float by design (not money); every
 * minor-unit figure stays integer. Rows are immutable history —
 * re-runs write a new generated_on, never update.
 *
 * @property int $id
 * @property int $branch_id
 * @property Carbon $stay_date
 * @property Carbon $generated_on
 * @property float $p_demand
 * @property int $expected_rooms
 * @property array<string, mixed>|null $features
 * @property string $model_version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 */
#[Fillable([
    'branch_id',
    'stay_date',
    'generated_on',
    'p_demand',
    'expected_rooms',
    'features',
    'model_version',
])]
class DemandForecast extends Model
{
    protected function casts(): array
    {
        return [
            'stay_date' => 'date',
            'generated_on' => 'date',
            'p_demand' => 'float',
            'features' => 'array',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }

    public function stale(int $hours = 48): bool
    {
        return $this->generated_on->lessThanOrEqualTo(now()->subHours($hours)->toDateString());
    }
}
