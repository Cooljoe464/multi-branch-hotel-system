<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Bookable event space (ballroom, garden) for banquet orders.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $name
 * @property int|null $capacity
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read Collection<int, BanquetEventOrder> $beos
 */
#[Fillable([
    'branch_id',
    'name',
    'capacity',
])]
class FunctionSpace extends Model
{
    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<BanquetEventOrder, $this> */
    public function beos(): HasMany
    {
        return $this->hasMany(BanquetEventOrder::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }
}
