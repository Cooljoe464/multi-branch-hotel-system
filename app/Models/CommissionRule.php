<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * OTA/agent commission terms: rate in bps over a net-room or gross
 * base. Accruals freeze the rate at checkout, so later rule edits
 * never restate history.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $source
 * @property int $rate_bps
 * @property string $base
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 */
#[Fillable([
    'branch_id',
    'source',
    'rate_bps',
    'base',
    'active',
])]
class CommissionRule extends Model
{
    public const BASE_NET_ROOM = 'net_room';

    public const BASE_GROSS = 'gross';

    protected function casts(): array
    {
        return [
            'rate_bps' => 'integer',
            'active' => 'boolean',
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

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
