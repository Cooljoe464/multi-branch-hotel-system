<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per provider + stay date holding the latest PMS-vs-OTA diff.
 * Re-runs upsert the same row, so the dashboard always shows the
 * current drift state, not run history.
 *
 * @property int $id
 * @property int $branch_id
 * @property int $channel_provider_id
 * @property Carbon $stay_date
 * @property array<string, mixed>|null $diff
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read ChannelProviderModel $channelProvider
 */
#[Fillable([
    'branch_id',
    'channel_provider_id',
    'stay_date',
    'diff',
    'status',
])]
class ChannelReconciliationRun extends Model
{
    public const STATUS_OK = 'ok';

    public const STATUS_DRIFT = 'drift';

    protected function casts(): array
    {
        return [
            'stay_date' => 'date',
            'diff' => 'array',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<ChannelProviderModel, $this> */
    public function channelProvider(): BelongsTo
    {
        return $this->belongsTo(ChannelProviderModel::class);
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
