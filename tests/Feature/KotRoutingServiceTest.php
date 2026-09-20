<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\KitchenStation;
use App\Models\MenuItem;
use App\Models\MenuItemStation;
use App\Services\KotRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KotRoutingServiceTest extends TestCase
{
    use RefreshDatabase;

    private KotRoutingService $service;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new KotRoutingService;
        $this->branch = Branch::factory()->create(['is_active' => true, 'settings' => ['time_zone' => 'Africa/Lagos', 'tax_rate' => 750]]);
    }

    public function test_route_items_with_no_mappings_returns_general(): void
    {
        $items = [
            ['menu_item_id' => 9999, 'name' => 'Burger', 'quantity' => 2, 'unit_price' => 1500, 'total' => 3000],
        ];

        $result = $this->service->routeItems($items, $this->branch->id);

        $this->assertEquals('general', $result[0]['outlet']);
    }

    public function test_route_items_with_valid_mapping_returns_station_code(): void
    {
        $station = KitchenStation::factory()->forBranch($this->branch->id)->create([
            'code' => 'GRILL',
            'is_active' => true,
        ]);
        $menuItem = MenuItem::factory()->forBranch($this->branch->id)->create();

        MenuItemStation::create([
            'menu_item_id' => $menuItem->id,
            'kitchen_station_id' => $station->id,
        ]);

        $items = [
            ['menu_item_id' => $menuItem->id, 'name' => $menuItem->name, 'quantity' => 1],
        ];

        $result = $this->service->routeItems($items, $this->branch->id);

        $this->assertEquals('GRILL', $result[0]['outlet']);
    }

    public function test_route_items_with_inactive_station_returns_general(): void
    {
        $station = KitchenStation::factory()->forBranch($this->branch->id)->create([
            'code' => 'COLD',
            'is_active' => false,
        ]);
        $menuItem = MenuItem::factory()->forBranch($this->branch->id)->create();

        MenuItemStation::create([
            'menu_item_id' => $menuItem->id,
            'kitchen_station_id' => $station->id,
        ]);

        $items = [
            ['menu_item_id' => $menuItem->id, 'name' => $menuItem->name, 'quantity' => 1],
        ];

        $result = $this->service->routeItems($items, $this->branch->id);

        $this->assertEquals('general', $result[0]['outlet']);
    }

    public function test_route_items_without_menu_item_id_returns_general(): void
    {
        $items = [
            ['name' => 'Custom Item', 'quantity' => 1],
        ];

        $result = $this->service->routeItems($items, $this->branch->id);

        $this->assertEquals('general', $result[0]['outlet']);
    }

    public function test_route_items_mixed_items_routes_correctly(): void
    {
        $station = KitchenStation::factory()->forBranch($this->branch->id)->create([
            'code' => 'PASTA',
            'is_active' => true,
        ]);
        $menuItem = MenuItem::factory()->forBranch($this->branch->id)->create();

        MenuItemStation::create([
            'menu_item_id' => $menuItem->id,
            'kitchen_station_id' => $station->id,
        ]);

        $items = [
            ['menu_item_id' => $menuItem->id, 'name' => $menuItem->name, 'quantity' => 1],
            ['name' => 'Drink', 'quantity' => 2],
        ];

        $result = $this->service->routeItems($items, $this->branch->id);

        $this->assertEquals('PASTA', $result[0]['outlet']);
        $this->assertEquals('general', $result[1]['outlet']);
    }
}
