<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $menu_item_id
 * @property int $kitchen_station_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MenuItem $menuItem
 * @property-read KitchenStation $kitchenStation
 */
#[Fillable(['menu_item_id', 'kitchen_station_id'])]
class MenuItemStation extends Model
{
    /** @return BelongsTo<MenuItem, $this> */
    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    /** @return BelongsTo<KitchenStation, $this> */
    public function kitchenStation(): BelongsTo
    {
        return $this->belongsTo(KitchenStation::class);
    }
}
