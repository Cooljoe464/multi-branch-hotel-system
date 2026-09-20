<?php

namespace App\Models;

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
 * @property array<int, array{name: string, quantity?: int, price?: int}> $items
 * @property int $subtotal
 * @property int $tax_amount
 * @property int $total
 * @property string $status
 * @property Carbon|null $posted_at
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read Reservation $reservation
 * @property-read Folio $folio
 * @property-read Transaction|null $transaction
 * @property-read Collection<int, KotItem> $kotItems
 */
#[Fillable([
    'branch_id',
    'currency_code',
    'business_date',
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
])]
class PosCharge extends Model
{
    /** @use HasFactory<PosChargeFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'subtotal' => 'integer',
            'tax_amount' => 'integer',
            'total' => 'integer',
            'posted_at' => 'datetime',
            'business_date' => 'date',
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
