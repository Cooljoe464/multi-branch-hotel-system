<?php

namespace App\Services;

use App\Models\MenuItemStation;

class KotRoutingService
{
    /**
     * Resolve outlet for each KOT item based on MenuItemStation mappings.
     *
     * @param  list<array{menu_item_id?: int, name: string, quantity?: int, unit_price?: int, total?: int, notes?: string|null}>  $items
     * @return list<array{menu_item_id?: int, name: string, quantity: int, unit_price?: int, total?: int, notes?: string|null, outlet: string}>
     */
    public function routeItems(array $items, int $branchId): array
    {
        $menuItemIds = array_filter(array_map(fn ($item) => $item['menu_item_id'] ?? null, $items));

        if (empty($menuItemIds)) {
            return array_map(fn ($item) => array_merge($item, ['outlet' => 'general', 'quantity' => $item['quantity'] ?? 1]), $items);
        }

        $stationMap = MenuItemStation::whereIn('menu_item_id', $menuItemIds)
            ->with('kitchenStation')
            ->get()
            ->keyBy('menu_item_id');

        return array_map(function ($item) use ($stationMap) {
            $menuItemId = $item['menu_item_id'] ?? null;
            $outlet = 'general';
            $quantity = $item['quantity'] ?? 1;

            if ($menuItemId && $stationMap->has($menuItemId)) {
                $stationMenuItem = $stationMap[$menuItemId];
                if ($stationMenuItem !== null) {
                    $station = $stationMenuItem->kitchenStation;
                    if ($station->is_active) {
                        $outlet = $station->code;
                    }
                }
            }

            return array_merge($item, ['outlet' => $outlet, 'quantity' => $quantity]);
        }, $items);
    }
}
