<?php

namespace App\Models;

use Database\Factories\DoorLockAuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $branch_id
 * @property int|null $reservation_id
 * @property int $room_id
 * @property int $gateway_id
 * @property string $action
 * @property string|null $credential_id
 * @property string|null $pin_code
 * @property Carbon $valid_from
 * @property Carbon $valid_until
 * @property string $status
 * @property array<string, mixed>|null $payload
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read Reservation|null $reservation
 * @property-read Room $room
 * @property-read DoorLockGateway $gateway
 */
#[Fillable([
    'branch_id',
    'reservation_id',
    'room_id',
    'gateway_id',
    'action',
    'credential_id',
    'pin_code',
    'valid_from',
    'valid_until',
    'status',
    'payload',
])]
class DoorLockAuditLog extends Model
{
    /** @use HasFactory<DoorLockAuditLogFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'payload' => 'array',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsTo<DoorLockGateway, $this> */
    public function gateway(): BelongsTo
    {
        return $this->belongsTo(DoorLockGateway::class, 'gateway_id');
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
    public function scopeForReservation(Builder $query, int $reservationId): Builder
    {
        return $query->where('reservation_id', $reservationId);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForRoom(Builder $query, int $roomId): Builder
    {
        return $query->where('room_id', $roomId);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForAction(Builder $query, string $action): Builder
    {
        return $query->where('action', $action);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('status', 'success');
    }

    public function markSuccess(string $credentialId): bool
    {
        return $this->update([
            'status' => 'success',
            'credential_id' => $credentialId,
        ]);
    }

    public function markFailed(string $errorMessage): bool
    {
        return $this->update([
            'status' => 'failed',
            'payload' => array_merge($this->payload ?? [], ['error' => $errorMessage]),
        ]);
    }
}
