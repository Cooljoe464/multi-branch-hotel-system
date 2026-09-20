<?php

namespace App\Models;

use Database\Factories\TabletOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $branch_id
 * @property int $tablet_session_id
 * @property int $reservation_id
 * @property array<int, array{menu_item_id: int, name: string, quantity?: int, unit_price?: int, total?: int, notes?: string}> $items
 * @property int $subtotal
 * @property int $tax_amount
 * @property int $total
 * @property string $status
 * @property string $payment_method
 * @property string $payment_status
 * @property string|null $payment_reference
 * @property array<string, mixed>|null $dietary_requests
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read TabletSession $tabletSession
 * @property-read Reservation $reservation
 */
#[Fillable([
    'branch_id',
    'currency_code',
    'tablet_session_id',
    'reservation_id',
    'items',
    'subtotal',
    'tax_amount',
    'total',
    'status',
    'payment_method',
    'payment_status',
    'payment_reference',
    'dietary_requests',
])]
class TabletOrder extends Model
{
    /** @use HasFactory<TabletOrderFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'subtotal' => 'integer',
            'tax_amount' => 'integer',
            'total' => 'integer',
            'dietary_requests' => 'array',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<TabletSession, $this> */
    public function tabletSession(): BelongsTo
    {
        return $this->belongsTo(TabletSession::class);
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
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
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePreparing(Builder $query): Builder
    {
        return $query->where('status', 'preparing');
    }
}
