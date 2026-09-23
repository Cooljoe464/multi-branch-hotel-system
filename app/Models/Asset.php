<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Maintainable asset with a preventive schedule
 * ({every_days, checklist}). PM due when last_pm_at is missing or
 * older than every_days.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $name
 * @property string $category
 * @property int|null $room_id
 * @property Carbon|null $installed_on
 * @property array<string, mixed>|null $pm_schedule
 * @property Carbon|null $last_pm_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read Room|null $room
 * @property-read Collection<int, MaintenanceTicket> $tickets
 */
#[Fillable([
    'branch_id',
    'name',
    'category',
    'room_id',
    'installed_on',
    'pm_schedule',
    'last_pm_at',
])]
class Asset extends Model
{
    protected function casts(): array
    {
        return [
            'installed_on' => 'date',
            'pm_schedule' => 'array',
            'last_pm_at' => 'date',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return HasMany<MaintenanceTicket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(MaintenanceTicket::class);
    }

    /** @return HasMany<MaintenanceTicket, $this> */
    public function recentTickets(): HasMany
    {
        return $this->hasMany(MaintenanceTicket::class)
            ->whereIn('status', ['open', 'in_progress'])
            ->orderByDesc('id')
            ->limit(5);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }

    public function pmEveryDays(): ?int
    {
        $schedule = $this->pm_schedule;
        $days = is_array($schedule) ? ($schedule['every_days'] ?? null) : null;

        return is_int($days) && $days > 0 ? $days : null;
    }
}
