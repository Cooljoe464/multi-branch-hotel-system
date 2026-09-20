<?php

namespace App\Models;

use App\Events\RoomStatusUpdated;
use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property int $branch_id
 * @property int $room_type_id
 * @property string $number
 * @property string|null $floor
 * @property string|null $wing
 * @property string $status
 * @property bool $is_accessible
 * @property bool $is_smoking
 * @property bool $is_active
 * @property string|null $notes
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Branch $branch
 * @property-read RoomType $roomType
 * @property-read Collection<int, Reservation> $reservations
 * @property-read Reservation|null $currentReservation
 */
#[Fillable([
    'branch_id',
    'room_type_id',
    'number',
    'floor',
    'wing',
    'status',
    'is_accessible',
    'is_smoking',
    'is_active',
    'notes',
    'metadata',
])]
class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory;

    use LogsActivity;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_accessible' => 'boolean',
            'is_smoking' => 'boolean',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'status', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected static function booted(): void
    {
        static::updated(function (Room $room) {
            if ($room->isDirty('status')) {
                broadcast(new RoomStatusUpdated($room));
            }
        });
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<RoomType, $this> */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /** @return HasMany<Reservation, $this> */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /** @return HasOne<Reservation, $this> */
    public function currentReservation(): HasOne
    {
        return $this->hasOne(Reservation::class)
            ->whereIn('status', ['checked_in', 'reserved'])
            ->where('check_in_date', '<=', now())
            ->where('check_out_date', '>', now());
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** @return HasMany<MaintenanceTicket, $this> */
    public function maintenanceTickets(): HasMany
    {
        return $this->hasMany(MaintenanceTicket::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', 'available')
            ->where('is_active', true);
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
    public function scopeForStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForFloor(Builder $query, string $floor): Builder
    {
        return $query->where('floor', $floor);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOccupied(Builder $query): Builder
    {
        return $query->where('status', 'occupied');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeNeedsCleaning(Builder $query): Builder
    {
        return $query->where('status', 'dirty');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOutOfService(Builder $query): Builder
    {
        return $query->where('status', 'out_of_order');
    }
}
