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
 * Priced ancillary: early check-in, late checkout, room upgrade.
 * Rules: {fee_minor, cutoff_hour, inventory_guard, target_room_type_id?}.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $kind
 * @property array<string, mixed>|null $rules
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read Collection<int, UpsellAcceptance> $acceptances
 */
#[Fillable([
    'branch_id',
    'kind',
    'rules',
    'active',
])]
class UpsellOffer extends Model
{
    public const KIND_EARLY_CHECKIN = 'early_checkin';

    public const KIND_LATE_CHECKOUT = 'late_checkout';

    public const KIND_UPGRADE = 'upgrade';

    protected function casts(): array
    {
        return [
            'rules' => 'array',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<UpsellAcceptance, $this> */
    public function acceptances(): HasMany
    {
        return $this->hasMany(UpsellAcceptance::class);
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
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function ruleInt(string $key, int $default = 0): int
    {
        $rules = $this->rules;
        $value = is_array($rules) ? ($rules[$key] ?? $default) : $default;

        return is_int($value) ? $value : $default;
    }
}
