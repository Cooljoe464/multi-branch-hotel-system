<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Time-window discount evaluated server-side when lines are added and
 * frozen on the charge. Window format: "FRI 17:00-19:00" or
 * "MON,TUE,WED 12:00-14:00". applies_to: {menu_item_ids?: int[]}.
 *
 * @property int $id
 * @property int $outlet_id
 * @property int $branch_id
 * @property string $cron_window
 * @property int $discount_bps
 * @property array<string, mixed>|null $applies_to
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Outlet $outlet
 * @property-read Branch $branch
 */
#[Fillable([
    'outlet_id',
    'branch_id',
    'cron_window',
    'discount_bps',
    'applies_to',
    'active',
])]
class HappyHour extends Model
{
    protected function casts(): array
    {
        return [
            'discount_bps' => 'integer',
            'applies_to' => 'array',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForOutlet(Builder $query, int $outletId): Builder
    {
        return $query->where('outlet_id', $outletId);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * @return list<int>
     */
    public function menuItemIds(): array
    {
        $applies = $this->applies_to;
        $ids = is_array($applies) ? ($applies['menu_item_ids'] ?? []) : [];

        if (! is_array($ids)) {
            return [];
        }

        $out = [];
        foreach ($ids as $id) {
            if (is_int($id)) {
                $out[] = $id;
            }
        }

        return $out;
    }
}
