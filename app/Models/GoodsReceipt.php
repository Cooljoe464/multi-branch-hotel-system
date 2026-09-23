<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One goods receipt against a PO. Idempotent on idempotency_key:
 * replays return the original receipt without restocking twice.
 *
 * @property int $id
 * @property int $branch_id
 * @property int $purchase_order_id
 * @property array<int, array<string, mixed>> $lines_received
 * @property int|null $received_by
 * @property string $idempotency_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Branch $branch
 * @property-read PurchaseOrder $purchaseOrder
 */
#[Fillable([
    'branch_id',
    'purchase_order_id',
    'lines_received',
    'received_by',
    'idempotency_key',
])]
class GoodsReceipt extends Model
{
    protected function casts(): array
    {
        return [
            'lines_received' => 'array',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
}
