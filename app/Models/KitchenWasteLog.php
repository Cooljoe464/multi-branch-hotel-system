<?php

namespace App\Models;

use Database\Factories\KitchenWasteLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $branch_id
 * @property int|null $menu_item_id
 * @property int|null $kot_item_id
 * @property string $reason
 * @property int $quantity
 * @property int $cost
 * @property string|null $notes
 * @property int|null $logged_by
 * @property Carbon|null $created_at
 * @property-read Branch $branch
 * @property-read MenuItem|null $menuItem
 */
#[Fillable([
    'branch_id',
    'currency_code',
    'menu_item_id',
    'kot_item_id',
    'reason',
    'quantity',
    'cost',
    'notes',
    'logged_by',
])]
class KitchenWasteLog extends Model
{
    /** @use HasFactory<KitchenWasteLogFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'cost' => 'integer',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<MenuItem, $this> */
    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function loggedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_by');
    }
}
