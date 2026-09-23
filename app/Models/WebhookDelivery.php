<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One signed outbound webhook attempt log. The signature is
 * computed over the frozen payload, so replays reproduce the
 * same signature while the secret is unchanged.
 *
 * @property int $id
 * @property int $api_consumer_id
 * @property string $event
 * @property array<string, mixed> $payload
 * @property string $signature
 * @property string $status
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ApiConsumer $consumer
 */
#[Fillable([
    'api_consumer_id',
    'event',
    'payload',
    'signature',
    'status',
    'attempts',
    'last_error',
])]
class WebhookDelivery extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    /** @return BelongsTo<ApiConsumer, $this> */
    public function consumer(): BelongsTo
    {
        return $this->belongsTo(ApiConsumer::class, 'api_consumer_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
