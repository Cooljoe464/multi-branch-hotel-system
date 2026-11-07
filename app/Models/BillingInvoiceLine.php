<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $billing_invoice_id
 * @property string $description
 * @property int $quantity
 * @property int $unit_amount_minor
 * @property int $amount_minor
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BillingInvoice $invoice
 */
#[Fillable(['tenant_id', 'billing_invoice_id', 'description', 'quantity', 'unit_amount_minor', 'amount_minor'])]
class BillingInvoiceLine extends Model
{
    /** @return BelongsTo<BillingInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoice::class, 'billing_invoice_id');
    }
}
