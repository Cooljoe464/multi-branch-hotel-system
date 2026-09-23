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
 * Room block held for a group (wedding, conference). Nights are held
 * per room type + stay date; pickup converts held nights into real
 * reservations; past-cutoff leftovers auto-release.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $name
 * @property string $code
 * @property Carbon $cutoff_date
 * @property int $attrition_pct
 * @property string $status
 * @property int|null $master_folio_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read Folio|null $masterFolio
 * @property-read Collection<int, GroupBlockNight> $nights
 * @property-read Collection<int, Reservation> $reservations
 * @property-read Collection<int, BanquetEventOrder> $beos
 */
#[Fillable([
    'branch_id',
    'name',
    'code',
    'cutoff_date',
    'attrition_pct',
    'status',
    'master_folio_id',
])]
class GroupBlock extends Model
{
    public const STATUS_TENTATIVE = 'tentative';

    public const STATUS_DEFINITE = 'definite';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_COMPLETED = 'completed';

    protected function casts(): array
    {
        return [
            'cutoff_date' => 'date',
            'attrition_pct' => 'integer',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Folio, $this> */
    public function masterFolio(): BelongsTo
    {
        return $this->belongsTo(Folio::class, 'master_folio_id');
    }

    /** @return HasMany<GroupBlockNight, $this> */
    public function nights(): HasMany
    {
        return $this->hasMany(GroupBlockNight::class);
    }

    /** @return HasMany<Reservation, $this> */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
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

    public function releasable(): bool
    {
        return in_array($this->status, [self::STATUS_TENTATIVE, self::STATUS_DEFINITE], true);
    }
}
