<?php

namespace App\Models;

use Database\Factories\PaymentTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $branch_id
 * @property int $folio_id
 * @property int|null $reservation_id
 * @property string $paystack_reference
 * @property string|null $paystack_access_code
 * @property string $type
 * @property string $status
 * @property int $amount
 * @property string $currency
 * @property string|null $customer_email
 * @property string|null $authorization_code
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $paid_at
 * @property array<string, mixed>|null $webhook_payload
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read Folio $folio
 * @property-read Reservation|null $reservation
 */
#[Fillable([
    'branch_id',
    'business_date',
    'folio_id',
    'reservation_id',
    'paystack_reference',
    'paystack_access_code',
    'type',
    'status',
    'amount',
    'currency',
    'customer_email',
    'authorization_code',
    'metadata',
    'paid_at',
    'webhook_payload',
])]
class PaymentTransaction extends Model
{
    /** @use HasFactory<PaymentTransactionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'business_date' => 'date',
            'metadata' => 'array',
            'paid_at' => 'datetime',
            'webhook_payload' => 'array',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Folio, $this> */
    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
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
    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('status', 'success');
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
    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeByReference(Builder $query, string $reference): Builder
    {
        return $query->where('paystack_reference', $reference);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function markSuccess(array $payload = []): bool
    {
        return $this->update(array_merge([
            'status' => 'success',
            'paid_at' => now(),
        ], $payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function markFailed(array $payload = []): bool
    {
        return $this->update(array_merge([
            'status' => 'failed',
        ], $payload));
    }
}
