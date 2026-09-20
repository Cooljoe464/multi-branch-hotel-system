<?php

namespace Tests\Feature;

use App\Events\KotItemStatusUpdated;
use App\Events\MenuItemStockToggled;
use App\Events\OrderStatusUpdated;
use App\Models\KotItem;
use App\Models\MenuItem;
use App\Models\TabletOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class BroadcastEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_kot_item_status_updated_event_broadcasts(): void
    {
        Event::fake([KotItemStatusUpdated::class]);

        $kotItem = KotItem::factory()->create(['status' => 'pending']);
        $previousStatus = 'pending';

        KotItemStatusUpdated::dispatch($kotItem, $previousStatus);

        Event::assertDispatched(KotItemStatusUpdated::class);
    }

    public function test_menu_item_stock_toggled_event_broadcasts(): void
    {
        Event::fake([MenuItemStockToggled::class]);

        $menuItem = MenuItem::factory()->create();

        MenuItemStockToggled::dispatch($menuItem, false);

        Event::assertDispatched(MenuItemStockToggled::class);
    }

    public function test_order_status_updated_event_broadcasts(): void
    {
        Event::fake([OrderStatusUpdated::class]);

        $order = TabletOrder::factory()->create(['status' => 'pending']);
        $previousStatus = 'pending';

        OrderStatusUpdated::dispatch($order, $previousStatus);

        Event::assertDispatched(OrderStatusUpdated::class);
    }
}
