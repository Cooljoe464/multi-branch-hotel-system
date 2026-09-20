<?php

namespace App\Models;

use Database\Factories\GroupLedgerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $branch_id
 * @property string $business_date
 * @property float $total_room_revenue
 * @property float $total_pos_revenue
 * @property float $total_tax
 * @property float $total_payments
 * @property float $net_revenue
 * @property string $currency_code
 * @property float $exchange_rate_to_group
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 */
#[Fillable([
    'branch_id',
    'business_date',
    'total_room_revenue',
    'total_pos_revenue',
    'total_tax',
    'total_payments',
    'net_revenue',
    'currency_code',
    'exchange_rate_to_group',
])]
class GroupLedger extends Model
{
    /** @use HasFactory<GroupLedgerFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'total_room_revenue' => 'float',
            'total_pos_revenue' => 'float',
            'total_tax' => 'float',
            'total_payments' => 'float',
            'net_revenue' => 'float',
            'exchange_rate_to_group' => 'float',
            'metadata' => 'array',
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

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForDate(Builder $query, string $date): Builder
    {
        return $query->where('business_date', $date);
    }
}
