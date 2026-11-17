<?php

namespace App\Models;

use Database\Factories\SupplierInputVatInvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $branch_id
 * @property int $supplier_id
 * @property string $supplier_invoice_number
 * @property string|null $supplier_tax_id
 * @property Carbon $invoice_date
 * @property Carbon $supply_date
 * @property string $currency_code
 * @property int $net_amount_minor
 * @property int $vat_amount_minor
 * @property int $taxable_use_minor
 * @property int $exempt_use_minor
 * @property bool $validated_einvoice
 * @property string|null $einvoice_reference
 * @property array<string, mixed>|null $evidence_snapshot
 * @property int|null $posted_by
 * @property Carbon $posted_at
 * @property int|null $voided_by
 * @property Carbon|null $voided_at
 * @property string|null $void_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'tenant_id',
    'branch_id',
    'supplier_id',
    'supplier_invoice_number',
    'supplier_tax_id',
    'invoice_date',
    'supply_date',
    'currency_code',
    'net_amount_minor',
    'vat_amount_minor',
    'taxable_use_minor',
    'exempt_use_minor',
    'validated_einvoice',
    'einvoice_reference',
    'evidence_snapshot',
    'posted_by',
    'posted_at',
    'voided_by',
    'voided_at',
    'void_reason',
])]
class SupplierInputVatInvoice extends Model
{
    /** @use HasFactory<SupplierInputVatInvoiceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'supply_date' => 'date',
            'net_amount_minor' => 'integer',
            'vat_amount_minor' => 'integer',
            'taxable_use_minor' => 'integer',
            'exempt_use_minor' => 'integer',
            'validated_einvoice' => 'boolean',
            'evidence_snapshot' => 'array',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
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
