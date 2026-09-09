<?php

namespace App\Models;

use App\Events\RoomStatusUpdated;
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
 * @property array|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Branch $branch
 * @property-read RoomType $roomType
 * @property-read Collection<Reservation> $reservations
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

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function currentReservation(): HasOne
    {
        return $this->hasOne(Reservation::class)
            ->whereIn('status', ['checked_in', 'reserved'])
            ->where('check_in_date', '<=', now())
            ->where('check_out_date', '>', now());
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function maintenanceTickets(): HasMany
    {
        return $this->hasMany(MaintenanceTicket::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', 'available')
            ->where('is_active', true);
    }

    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeForStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeForFloor(Builder $query, string $floor): Builder
    {
        return $query->where('floor', $floor);
    }

    public function scopeOccupied(Builder $query): Builder
    {
        return $query->where('status', 'occupied');
    }

    public function scopeNeedsCleaning(Builder $query): Builder
    {
        return $query->where('status', 'dirty');
    }

    public function scopeOutOfService(Builder $query): Builder
    {
        return $query->where('status', 'out_of_order');
    }
}
