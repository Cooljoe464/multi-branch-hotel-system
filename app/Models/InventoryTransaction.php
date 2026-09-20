<?php

namespace App\Models;

use Database\Factories\InventoryTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $branch_id
 * @property int $inventory_item_id
 * @property string $type
 * @property float $quantity
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string|null $notes
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property-read InventoryItem $inventoryItem
 */
#[Fillable([
    'branch_id',
    'inventory_item_id',
    'type',
    'quantity',
    'reference_type',
    'reference_id',
    'notes',
    'created_by',
])]
class InventoryTransaction extends Model
{
    /** @use HasFactory<InventoryTransactionFactory> */
    use HasFactory;

    protected $table = 'inventory_transactions';

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
        ];
    }

    /** @return BelongsTo<InventoryItem, $this> */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    /** @return MorphTo<Model, $this> */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
