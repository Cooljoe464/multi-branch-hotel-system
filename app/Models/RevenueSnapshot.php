<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Daily revenue grain per stay date, rebuilt nightly. Past dates carry
 * audited actuals (from daily ledgers); future dates carry on-the-books
 * pickup. Pace compares the same stay date across snapshot dates.
 *
 * @property int $id
 * @property int $branch_id
 * @property Carbon $stay_date
 * @property Carbon $snapshot_date
 * @property int $rooms_available
 * @property int $rooms_sold
 * @property int $room_revenue_minor
 * @property int $total_revenue_minor
 * @property int $gop_expense_minor
 * @property array<string, mixed>|null $by_segment
 * @property array<string, mixed>|null $by_source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 */
#[Fillable([
    'branch_id',
    'stay_date',
    'snapshot_date',
    'rooms_available',
    'rooms_sold',
    'room_revenue_minor',
    'total_revenue_minor',
    'gop_expense_minor',
    'by_segment',
    'by_source',
])]
class RevenueSnapshot extends Model
{
    protected function casts(): array
    {
        return [
            'stay_date' => 'date',
            'snapshot_date' => 'date',
            'by_segment' => 'array',
            'by_source' => 'array',
        ];
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
}
