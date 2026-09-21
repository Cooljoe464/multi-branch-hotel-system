<?php

namespace App\Models;

use Database\Factories\JournalEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Immutable double-entry journal. No update or delete path exists on
 * purpose: corrections are compensating reversal entries.
 *
 * @property int $id
 * @property int $branch_id
 * @property Carbon $business_date
 * @property string $event
 * @property string $debit_account
 * @property string $credit_account
 * @property int $amount_minor
 * @property string $currency_code
 * @property int $fx_rate_to_branch_minor
 * @property string|null $source_type
 * @property int|null $source_id
 * @property string|null $idempotency_scope
 * @property string|null $idempotency_key
 * @property int|null $created_by
 * @property Carbon|null $posted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 */
#[Fillable([
    'branch_id',
    'business_date',
    'event',
    'debit_account',
    'credit_account',
    'amount_minor',
    'currency_code',
    'fx_rate_to_branch_minor',
    'source_type',
    'source_id',
    'idempotency_scope',
    'idempotency_key',
    'created_by',
    'posted_at',
])]
class JournalEntry extends Model
{
    /** @use HasFactory<JournalEntryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'amount_minor' => 'integer',
            'fx_rate_to_branch_minor' => 'integer',
            'posted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return MorphTo<Model, $this> */
    public function source(): MorphTo
    {
        return $this->morphTo();
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
    public function scopeForBusinessDate(Builder $query, string $date): Builder
    {
        return $query->where('business_date', $date);
    }
}
