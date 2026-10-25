<?php

namespace App\Models;

use Database\Factories\ConciergeThreadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One WhatsApp conversation per guest × branch. A thread links the
 * Meta wa_id to a guest/reservation so the concierge can answer with
 * grounded context; handoff pauses AI auto-replies until staff hands
 * the conversation back.
 *
 * @property int $id
 * @property int $branch_id
 * @property int|null $guest_id
 * @property int|null $reservation_id
 * @property int|null $assigned_to
 * @property string $channel
 * @property string $status
 * @property string $whatsapp_wa_id
 * @property string|null $phone_number_id
 * @property Carbon|null $last_message_at
 * @property Carbon|null $handoff_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read Guest|null $guest
 * @property-read Reservation|null $reservation
 * @property-read User|null $assignee
 * @property-read Collection<int, ConciergeMessage> $messages
 * @property-read Collection<int, ConciergeAction> $actions
 */
#[Fillable([
    'branch_id',
    'guest_id',
    'reservation_id',
    'assigned_to',
    'channel',
    'status',
    'whatsapp_wa_id',
    'phone_number_id',
    'last_message_at',
    'handoff_at',
    'metadata',
])]
class ConciergeThread extends Model
{
    /** @use HasFactory<ConciergeThreadFactory> */
    use HasFactory;

    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_HANDOFF = 'handoff';

    public const STATUS_CLOSED = 'closed';

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'handoff_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

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

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return HasMany<ConciergeMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(ConciergeMessage::class);
    }

    /** @return HasMany<ConciergeAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(ConciergeAction::class);
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
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_HANDOFF]);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAwaitingHuman(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_HANDOFF);
    }

    public function displayName(): string
    {
        $guest = $this->guest;

        if ($guest instanceof Guest) {
            return $guest->full_name;
        }

        $name = $this->metadata['profile_name'] ?? null;

        return is_string($name) && $name !== '' ? $name : $this->whatsapp_wa_id;
    }

    public function isHandoff(): bool
    {
        return $this->status === self::STATUS_HANDOFF;
    }
}
