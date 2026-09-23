<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One balanced journal batch per property, date and provider. The
 * unique key makes re-runs return the original external id instead
 * of double-posting.
 *
 * @property int $id
 * @property int $branch_id
 * @property Carbon $business_date
 * @property string $provider
 * @property string $status
 * @property string|null $external_id
 * @property array<string, mixed>|null $totals
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 */
#[Fillable([
    'branch_id',
    'business_date',
    'provider',
    'status',
    'external_id',
    'totals',
    'last_error',
])]
class AccountingExport extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'totals' => 'array',
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

    public static function externalRef(int $branchId, string $businessDate): string
    {
        return "PMS-{$branchId}-{$businessDate}";
    }
}
