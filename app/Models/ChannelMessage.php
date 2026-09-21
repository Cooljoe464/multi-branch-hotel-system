<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * ARI outbox: every OTA-bound message is a row first, transport second.
 * The idempotency key makes enqueue, send retries and dashboard replays
 * converge on a single OTA-side effect.
 *
 * @property int $id
 * @property int $branch_id
 * @property int|null $channel_provider_id
 * @property string $channel
 * @property string $kind
 * @property array<string, mixed> $payload
 * @property string $idempotency_key
 * @property string $status
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read ChannelProviderModel|null $channelProvider
 */
#[Fillable([
    'branch_id',
    'channel_provider_id',
    'channel',
    'kind',
    'payload',
    'idempotency_key',
    'status',
    'attempts',
    'last_error',
])]
class ChannelMessage extends Model
{
    public const KIND_ARI = 'ari';

    public const KIND_RESERVATION = 'reservation';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENDING = 'sending';

    public const STATUS_ACKED = 'acked';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
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

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }
}
