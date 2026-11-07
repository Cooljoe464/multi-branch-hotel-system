<?php

namespace App\Models;

use Database\Factories\BillingInvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The platform's own subscription invoice to a tenant. Amounts are integer
 * minor units and are frozen at issue.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $subscription_id
 * @property string $number
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string $currency
 * @property int $subtotal_minor
 * @property int $tax_minor
 * @property int $total_minor
 * @property string $status
 * @property Carbon $issued_at
 * @property Carbon $due_at
 * @property Carbon|null $paid_at
 * @property string|null $payment_reference
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Subscription $subscription
 * @property-read Collection<int, BillingInvoiceLine> $lines
 */
#[Fillable([
    'tenant_id', 'subscription_id', 'number', 'period_start', 'period_end', 'currency',
    'subtotal_minor', 'tax_minor', 'total_minor', 'status', 'issued_at', 'due_at',
    'paid_at', 'payment_reference',
])]
class BillingInvoice extends Model
{
    /** @use HasFactory<BillingInvoiceFactory> */
    use HasFactory;

    public const STATUS_ISSUED = 'issued';

    public const STATUS_PAID = 'paid';

    public const STATUS_VOID = 'void';

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return HasMany<BillingInvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(BillingInvoiceLine::class);
    }
}
