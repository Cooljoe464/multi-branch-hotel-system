<?php

namespace App\Models;

use Database\Factories\DailyLedgerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property int $branch_id
 * @property Carbon $business_date
 * @property string $status
 * @property int $rooms_posted
 * @property int $total_room_revenue
 * @property int $total_tax
 * @property int $total_other_charges
 * @property int $total_payments
 * @property int $net_revenue
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property list<array{reservation_id: int, error: string}>|null $errors
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 */
#[Fillable([
    'branch_id',
    'currency_code',
    'business_date',
    'status',
    'rooms_posted',
    'total_room_revenue',
    'total_tax',
    'total_other_charges',
    'total_payments',
    'net_revenue',
    'started_at',
    'completed_at',
    'errors',
    'metadata',
])]
class DailyLedger extends Model
{
    /** @use HasFactory<DailyLedgerFactory> */
    use HasFactory;

    use LogsActivity;

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'rooms_posted' => 'integer',
            'total_room_revenue' => 'integer',
            'total_tax' => 'integer',
            'total_other_charges' => 'integer',
            'total_payments' => 'integer',
            'net_revenue' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'errors' => 'array',
            'metadata' => 'array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'rooms_posted', 'total_room_revenue', 'total_tax', 'completed_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
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
    public function scopeForDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('business_date', $date);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }

    public function markInProgress(): bool
    {
        return $this->update([
            'status' => 'in_progress',
            'started_at' => now(),
        ]);
    }

    /**
     * @param  array{rooms_posted: int, total_room_revenue: int, total_tax: int, total_other_charges: int, total_payments: int, net_revenue: int}  $totals
     */
    public function markCompleted(array $totals): bool
    {
        return $this->update(array_merge($totals, [
            'status' => 'completed',
            'completed_at' => now(),
        ]));
    }

    /**
     * @param  list<array{reservation_id: int, error: string}>  $errors
     */
    public function markFailed(array $errors): bool
    {
        return $this->update([
            'status' => 'failed',
            'errors' => $errors,
        ]);
    }
}
