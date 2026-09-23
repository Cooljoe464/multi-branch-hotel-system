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
 * Floor-plan table inside an outlet. Seating a tab flips free→seated;
 * posting/voiding it frees the table again.
 *
 * @property int $id
 * @property int $outlet_id
 * @property int $branch_id
 * @property string $code
 * @property string $shape
 * @property int $seats
 * @property array<string, mixed>|null $position
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Outlet $outlet
 * @property-read Branch $branch
 * @property-read Collection<int, PosCharge> $charges
 */
#[Fillable([
    'outlet_id',
    'branch_id',
    'code',
    'shape',
    'seats',
    'position',
    'status',
])]
class DiningTable extends Model
{
    public const STATUS_FREE = 'free';

    public const STATUS_SEATED = 'seated';

    protected function casts(): array
    {
        return [
            'seats' => 'integer',
            'position' => 'array',
        ];
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<PosCharge, $this> */
    public function charges(): HasMany
    {
        return $this->hasMany(PosCharge::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForOutlet(Builder $query, int $outletId): Builder
    {
        return $query->where('outlet_id', $outletId);
    }
}
