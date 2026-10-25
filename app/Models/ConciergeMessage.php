<?php

namespace App\Models;

use Database\Factories\ConciergeMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One chat turn. Guest rows carry the Meta message id (dedupe on
 * redelivery); assistant rows carry the tool calls they requested.
 *
 * @property int $id
 * @property int $concierge_thread_id
 * @property string $role
 * @property string $body
 * @property array<int, mixed>|null $tool_calls
 * @property array<string, mixed>|null $metadata
 * @property string|null $whatsapp_message_id
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ConciergeThread $thread
 */
#[Fillable([
    'concierge_thread_id',
    'role',
    'body',
    'tool_calls',
    'metadata',
    'whatsapp_message_id',
    'read_at',
])]
class ConciergeMessage extends Model
{
    /** @use HasFactory<ConciergeMessageFactory> */
    use HasFactory;

    public const ROLE_GUEST = 'guest';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_AGENT = 'agent';

    public const ROLE_SYSTEM = 'system';

    public const ROLE_TOOL = 'tool';

    protected function casts(): array
    {
        return [
            'tool_calls' => 'array',
            'metadata' => 'array',
            'read_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ConciergeThread, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(ConciergeThread::class, 'concierge_thread_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function isFromGuest(): bool
    {
        return $this->role === self::ROLE_GUEST;
    }
}
