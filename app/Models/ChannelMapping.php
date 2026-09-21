<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * OTA room/rate code → PMS room type + rate plan. Every ARI push and
 * inbound reservation resolves through this table; unmapped OTA codes
 * are rejected instead of guessed.
 *
 * @property int $id
 * @property int $branch_id
 * @property int $channel_provider_id
 * @property string $channel
 * @property int $room_type_id
 * @property int $rate_plan_id
 * @property string $channel_room_code
 * @property string $channel_rate_code
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read ChannelProviderModel $channelProvider
 * @property-read RoomType $roomType
 * @property-read RatePlan $ratePlan
 */
#[Fillable([
    'branch_id',
    'channel_provider_id',
    'channel',
    'room_type_id',
    'rate_plan_id',
    'channel_room_code',
    'channel_rate_code',
])]
class ChannelMapping extends Model
{
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

    /** @return BelongsTo<RoomType, $this> */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /** @return BelongsTo<RatePlan, $this> */
    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class);
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
