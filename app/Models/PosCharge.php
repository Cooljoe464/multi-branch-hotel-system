<?php

namespace App\Models;

use App\Concerns\HasOptimisticLock;
use Database\Factories\PosChargeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $branch_id
 * @property int $reservation_id
 * @property int $folio_id
 * @property int|null $transaction_id
 * @property string $outlet
 * @property array<int, array<string, mixed>> $items
 * @property int $subtotal
 * @property int $tax_amount
 * @property int $total
 * @property int $version
 * @property string $status
 * @property int|null $dining_table_id
 * @property string $course
 * @property Carbon|null $fired_at
 * @property string|null $offline_nonce
 * @property int|null $parent_split_id
 * @property Carbon|null $posted_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read Reservation $reservation
 * @property-read Folio $folio
 * @property-read Transaction|null $transaction
 * @property-read Collection<int, KotItem> $kotItems
 * @property-read DiningTable|null $diningTable
 * @property-read PosCharge|null $parentSplit
 * @property-read Collection<int, PosCharge> $splitChildren
 */
#[Fillable([
    'branch_id',
    'currency_code',
    'business_date',
    'idempotency_key',
    'reservation_id',
    'folio_id',
    'transaction_id',
    'outlet',
    'items',
    'subtotal',
    'tax_amount',
    'total',
    'status',
    'posted_at',
    'metadata',
    'version',
    'dining_table_id',
    'course',
    'fired_at',
    'offline_nonce',
    'parent_split_id',
])]
class PosCharge extends Model
{
    /** @use HasFactory<PosChargeFactory> */
    use HasFactory;

    use HasOptimisticLock;

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'subtotal' => 'integer',
            'tax_amount' => 'integer',
            'total' => 'integer',
            'posted_at' => 'datetime',
            'fired_at' => 'datetime',
            'business_date' => 'date',
            'version' => 'integer',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** @return BelongsTo<Folio, $this> */
    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /** @return HasMany<KotItem, $this> */
    public function kotItems(): HasMany
    {
        return $this->hasMany(KotItem::class);
    }

    /** @return BelongsTo<DiningTable, $this> */
    public function diningTable(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class);
    }

    /** @return BelongsTo<PosCharge, $this> */
    public function parentSplit(): BelongsTo
    {
        return $this->belongsTo(PosCharge::class, 'parent_split_id');
    }

    /** @return HasMany<PosCharge, $this> */
    public function splitChildren(): HasMany
    {
        return $this->hasMany(PosCharge::class, 'parent_split_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', 'posted');
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
    public function scopeForOutlet(Builder $query, string $outlet): Builder
    {
        return $query->where('outlet', $outlet);
    }
}
