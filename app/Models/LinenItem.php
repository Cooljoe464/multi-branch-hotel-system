<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $branch_id
 * @property string $name
 * @property string $category
 * @property int $par_level
 * @property int $current_clean
 * @property int $current_dirty
 * @property int $current_in_laundry
 * @property int $unit_cost_minor
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 */
#[Fillable([
    'branch_id',
    'name',
    'category',
    'par_level',
    'current_clean',
    'current_dirty',
    'current_in_laundry',
    'unit_cost_minor',
])]
class LinenItem extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'par_level' => 'integer',
            'current_clean' => 'integer',
            'current_dirty' => 'integer',
            'current_in_laundry' => 'integer',
            'unit_cost_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<LinenDiscard, $this> */
    public function discards(): HasMany
    {
        return $this->hasMany(LinenDiscard::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeBelowPar(Builder $query): Builder
    {
        return $query->whereRaw('(current_clean + current_in_laundry) < par_level');
    }
}
