<?php

namespace App\Models;

use Database\Factories\MaintenanceTicketFactory;
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
 * @property int $reported_by
 * @property int|null $assigned_to
 * @property string $ticket_number
 * @property string $category
 * @property string $priority
 * @property string $status
 * @property string $title
 * @property string $description
 * @property string|null $resolution_notes
 * @property bool $is_room_locked
 * @property int|null $asset_id
 * @property Carbon|null $sla_due_at
 * @property Carbon|null $responded_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property int|null $estimated_cost
 * @property int|null $actual_cost
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Branch $branch
 * @property-read Room|null $room
 * @property-read Asset|null $asset
 * @property-read User $reporter
 * @property-read User|null $assignee
 */
#[Fillable([
    'branch_id',
    'currency_code',
    'room_id',
    'reported_by',
    'assigned_to',
    'ticket_number',
    'category',
    'priority',
    'status',
    'title',
    'description',
    'resolution_notes',
    'is_room_locked',
    'asset_id',
    'sla_due_at',
    'responded_at',
    'resolved_at',
    'estimated_cost',
    'actual_cost',
    'metadata',
])]
class MaintenanceTicket extends Model
{
    /** @use HasFactory<MaintenanceTicketFactory> */
    use HasFactory;

    use LogsActivity;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_room_locked' => 'boolean',
            'sla_due_at' => 'datetime',
            'responded_at' => 'datetime',
            'resolved_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'estimated_cost' => 'integer',
            'actual_cost' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'assigned_to', 'priority', 'is_room_locked'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (MaintenanceTicket $ticket) {
            if (empty($ticket->ticket_number)) {
                $ticket->ticket_number = static::generateTicketNumber();
            }
        });
    }

    public static function generateTicketNumber(): string
    {
        do {
            $number = 'MNT-'.date('Ym').'-'.str_pad((string) rand(1, 9999), 4, '0', STR_PAD_LEFT);
        } while (static::where('ticket_number', $number)->exists());

        return $number;
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

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['open', 'in_progress']);
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
    public function scopeForCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
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
    public function scopeForPriority(Builder $query, string $priority): Builder
    {
        return $query->where('priority', $priority);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeLockedRooms(Builder $query): Builder
    {
        return $query->where('is_room_locked', true)
            ->where('status', '!=', 'completed');
    }

    public function lockRoom(): void
    {
        $this->update(['is_room_locked' => true]);

        if ($this->room) {
            $this->room->update(['status' => 'out_of_order']);
        }
    }

    public function unlockRoom(): void
    {
        $this->update(['is_room_locked' => false]);

        if ($this->room && $this->room->status === 'out_of_order') {
            $this->room->update(['status' => 'available']);
        }
    }

    public function start(): void
    {
        $this->update([
            'status' => 'in_progress',
            'started_at' => now(),
        ]);
    }

    public function complete(?string $resolutionNotes = null, ?int $actualCost = null): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'resolution_notes' => $resolutionNotes ?? $this->resolution_notes,
            'actual_cost' => $actualCost ?? $this->actual_cost,
        ]);

        if ($this->is_room_locked) {
            $this->unlockRoom();
        }

        if ($this->room && $this->room->status !== 'out_of_order') {
            $this->room->update(['status' => 'dirty']);

            Task::create([
                'branch_id' => $this->branch_id,
                'room_id' => $this->room_id,
                'type' => 'turnover',
                'priority' => 'normal',
                'status' => 'pending',
                'description' => "Post-maintenance cleaning for ticket {$this->ticket_number}: {$this->title}",
                'estimated_minutes' => 30,
            ]);
        }
    }
}
