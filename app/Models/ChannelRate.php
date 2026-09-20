<?php

namespace App\Models;

use Database\Factories\ChannelRateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $channel_provider_id
 * @property int $rate_plan_id
 * @property int $room_type_id
 * @property string|null $channel_rate_code
 * @property string|null $external_rate_id
 * @property bool $is_synced
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ChannelProviderModel $channelProvider
 */
#[Fillable(['channel_provider_id', 'rate_plan_id', 'room_type_id', 'channel_rate_code', 'external_rate_id', 'is_synced', 'last_synced_at'])]
class ChannelRate extends Model
{
    /** @use HasFactory<ChannelRateFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_synced' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ChannelProviderModel, $this> */
    public function channelProvider(): BelongsTo
    {
        return $this->belongsTo(ChannelProviderModel::class);
    }

    /** @return BelongsTo<RatePlan, $this> */
    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class);
    }

    /** @return BelongsTo<RoomType, $this> */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }
}
