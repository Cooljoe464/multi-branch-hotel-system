<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property int $branch_id
 * @property int|null $room_id
 * @property int|null $assigned_to
 * @property string $type
 * @property string $priority
 * @property string $status
 * @property string|null $description
 * @property string|null $notes
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property int|null $estimated_minutes
 * @property int|null $actual_minutes
 * @property array|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Branch $branch
 * @property-read Room|null $room
 * @property-read User|null $assignee
 */
#[Fillable([
    'branch_id',
    'room_id',
    'assigned_to',
    'type',
    'priority',
    'status',
    'description',
    'notes',
    'estimated_minutes',
    'actual_minutes',
    'metadata',
])]
class Task extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'estimated_minutes' => 'integer',
            'actual_minutes' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'assigned_to', 'priority'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'in_progress']);
    }

    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeForType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeForStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeForPriority(Builder $query, string $priority): Builder
    {
        return $query->where('priority', $priority);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('assigned_to', $userId);
    }

    public function scopeUnassigned(Builder $query): Builder
    {
        return $query->whereNull('assigned_to');
    }

    public function start(): void
    {
        $this->update([
            'status' => 'in_progress',
            'started_at' => now(),
        ]);
    }

    public function complete(?string $notes = null): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'actual_minutes' => $this->started_at
                ? (int) $this->started_at->diffInMinutes(now())
                : null,
            'notes' => $notes ?? $this->notes,
        ]);
    }
}
