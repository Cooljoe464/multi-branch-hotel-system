<?php

namespace App\Models;

use Database\Factories\ConciergeActionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A draft the concierge will not apply on its own. Money and
 * maintenance actions wait for the guest to confirm and a staff member
 * to approve; applying calls the owning service (UpsellService,
 * MaintenanceService) so no model writes booking or folio tables.
 *
 * @property int $id
 * @property int $concierge_thread_id
 * @property int|null $reservation_id
 * @property int|null $applied_by
 * @property string $type
 * @property string $status
 * @property array<string, mixed>|null $payload
 * @property string $idempotency_key
 * @property Carbon|null $guest_confirmed_at
 * @property Carbon|null $applied_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ConciergeThread $thread
 * @property-read Reservation|null $reservation
 * @property-read User|null $appliedBy
 */
#[Fillable([
    'concierge_thread_id',
    'reservation_id',
    'applied_by',
    'type',
    'status',
    'payload',
    'idempotency_key',
    'guest_confirmed_at',
    'applied_at',
])]
class ConciergeAction extends Model
{
    /** @use HasFactory<ConciergeActionFactory> */
    use HasFactory;

    public const TYPE_LATE_CHECKOUT = 'late_checkout';

    public const TYPE_EARLY_CHECKIN = 'early_checkin';

    public const TYPE_UPGRADE = 'upgrade';

    public const TYPE_MAINTENANCE = 'maintenance';

    public const STATUS_PENDING_GUEST_CONFIRM = 'pending_guest_confirm';

    public const STATUS_GUEST_CONFIRMED = 'guest_confirmed';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_EXPIRED = 'expired';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'guest_confirmed_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ConciergeThread, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(ConciergeThread::class, 'concierge_thread_id');
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING_GUEST_CONFIRM, self::STATUS_GUEST_CONFIRMED]);
    }

    public function isPendingGuestConfirm(): bool
    {
        return $this->status === self::STATUS_PENDING_GUEST_CONFIRM;
    }
}
