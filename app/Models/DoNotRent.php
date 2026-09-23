<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Do-not-rent listing. Null branch_id means all properties; matching
 * is by guest row or email (pre-guest bookings).
 *
 * @property int $id
 * @property int|null $branch_id
 * @property int|null $guest_id
 * @property string|null $email
 * @property string $reason
 * @property int $listed_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch|null $branch
 * @property-read Guest|null $guest
 */
#[Fillable([
    'branch_id',
    'guest_id',
    'email',
    'reason',
    'listed_by',
])]
class DoNotRent extends Model
{
    protected $table = 'do_not_rent';

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Guest, $this> */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where(function ($q) use ($branchId) {
            $q->where('branch_id', $branchId)->orWhereNull('branch_id');
        });
    }
}
