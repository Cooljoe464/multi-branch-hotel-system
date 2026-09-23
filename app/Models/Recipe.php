<?php

namespace App\Models;

use Database\Factories\RecipeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $branch_id
 * @property int $menu_item_id
 * @property int $inventory_item_id
 * @property float $quantity_required
 * @property int $yield_qty
 * @property int $wastage_bps
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MenuItem $menuItem
 * @property-read InventoryItem|null $inventoryItem
 */
#[Fillable([
    'branch_id',
    'menu_item_id',
    'inventory_item_id',
    'quantity_required',
    'yield_qty',
    'wastage_bps',
    'notes',
])]
class Recipe extends Model
{
    /** @use HasFactory<RecipeFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'quantity_required' => 'float',
            'yield_qty' => 'integer',
            'wastage_bps' => 'integer',
        ];
    }

    /** @return BelongsTo<MenuItem, $this> */
    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    /** @return BelongsTo<InventoryItem, $this> */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
