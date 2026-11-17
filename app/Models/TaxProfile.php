<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $branch_id
 * @property string $jurisdiction
 * @property string $name
 * @property bool $active
 * @property Carbon|null $effective_from
 * @property Carbon|null $effective_until
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 */
#[Fillable([
    'branch_id',
    'jurisdiction',
    'name',
    'active',
    'effective_from',
    'effective_until',
])]
class TaxProfile extends Model
{
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<TaxComponent, $this> */
    public function components(): HasMany
    {
        return $this->hasMany(TaxComponent::class)->orderBy('sequence');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }

    public function effectiveOn(Carbon|string|null $date = null): bool
    {
        $day = $date instanceof Carbon ? $date->startOfDay() : Carbon::parse($date ?? now())->startOfDay();

        return $this->active
            && ($this->effective_from === null || $this->effective_from->startOfDay()->lessThanOrEqualTo($day))
            && ($this->effective_until === null || $this->effective_until->startOfDay()->greaterThanOrEqualTo($day));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeEffectiveOn(Builder $query, Carbon|string $date): Builder
    {
        $day = $date instanceof Carbon ? $date->toDateString() : $date;

        return $query
            ->where('active', true)
            ->where(function (Builder $query) use ($day): void {
                $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $day);
            })
            ->where(function (Builder $query) use ($day): void {
                $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $day);
            });
    }
}
