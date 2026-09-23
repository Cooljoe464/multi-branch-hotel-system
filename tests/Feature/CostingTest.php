<?php

use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\JournalEntry;
use App\Models\MenuItem;
use App\Models\PosCharge;
use App\Models\PurchaseOrder;
use App\Models\Recipe;
use App\Models\Supplier;
use App\Services\CostingService;
use Database\Seeders\ChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['timezone' => 'Africa/Lagos']);
    $this->user = $this->makeAdminUser($this->branch);
    $this->seed(ChartSeeder::class);
    $this->service = app(CostingService::class);

    $this->flour = InventoryItem::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Flour',
        'category' => 'food',
        'unit' => 'kg',
        'current_quantity' => 10,
        'reorder_point' => 1,
        'cost_per_unit' => 100,
    ]);
    $this->patty = InventoryItem::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Beef Patty',
        'category' => 'food',
        'unit' => 'piece',
        'current_quantity' => 10,
        'reorder_point' => 1,
        'cost_per_unit' => 500,
    ]);
    $this->burger = MenuItem::factory()->create([
        'branch_id' => $this->branch->id,
        'category' => 'mains',
        'name' => 'Burger',
        'price' => 5000,
        'is_available' => true,
        'is_active' => true,
    ]);
    Recipe::create([
        'branch_id' => $this->branch->id,
        'menu_item_id' => $this->burger->id,
        'inventory_item_id' => $this->flour->id,
        'quantity_required' => 0.2,
        'yield_qty' => 1,
        'wastage_bps' => 0,
    ]);
    Recipe::create([
        'branch_id' => $this->branch->id,
        'menu_item_id' => $this->burger->id,
        'inventory_item_id' => $this->patty->id,
        'quantity_required' => 1,
        'yield_qty' => 1,
        'wastage_bps' => 0,
    ]);
});

it('depletes recipes exactly and journals COGS', function () {
    $cogs = $this->service->depleteMenuItem($this->burger, 2, $this->user);

    // Flour 0.2×2 = 0.4 @100 = 40; patty 1×2 = 2 @500 = 1000.
    expect($cogs)->toBe(1040);
    expect($this->flour->fresh()->current_quantity)->toBe(9.6);
    expect($this->patty->fresh()->current_quantity)->toBe(8.0);
    expect((int) JournalEntry::where('event', 'cogs.recognized')->sum('amount_minor'))->toBe(1040);
});

it('blocks stock-outs instead of driving negative', function () {
    $this->patty->update(['current_quantity' => 1]);

    $this->service->depleteMenuItem($this->burger, 1, $this->user);

    expect($this->patty->fresh()->current_quantity)->toBe(0.0);
    expect(fn () => $this->service->depleteMenuItem($this->burger, 1, $this->user))
        ->toThrow(AvailabilityException::class, 'Insufficient');
    expect($this->patty->fresh()->current_quantity)->toBe(0.0);
});

it('revalues weighted cost on GRN without double posting', function () {
    $supplier = Supplier::create(['branch_id' => $this->branch->id, 'name' => 'Farms Ltd']);

    $po = PurchaseOrder::create([
        'branch_id' => $this->branch->id,
        'supplier_id' => $supplier->id,
        'lines' => [['inventory_item_id' => $this->flour->id, 'qty' => 10, 'unit_cost_minor' => 200]],
        'status' => 'sent',
    ]);

    // (10×100 + 10×200) / 20 = 150.
    $receipt = $this->service->receive(
        $po,
        [['inventory_item_id' => $this->flour->id, 'qty' => 10, 'unit_cost_minor' => 200]],
        $this->user,
        'grn-1',
    );

    expect($this->flour->fresh()->current_quantity)->toBe(20.0);
    expect($this->flour->fresh()->cost_per_unit)->toBe(150);
    expect($po->fresh()->status)->toBe('received');
    expect(JournalEntry::where('event', 'inventory.received')->where('amount_minor', 2000)->count())->toBe(1);

    // Same key replays the original receipt: no restock, no journal.
    $again = $this->service->receive(
        $po->fresh(), [['inventory_item_id' => $this->flour->id, 'qty' => 10, 'unit_cost_minor' => 200]],
        $this->user, 'grn-1',
    );

    expect($again->id)->toBe($receipt->id);
    expect($this->flour->fresh()->current_quantity)->toBe(20.0);
    expect(JournalEntry::where('event', 'inventory.received')->count())->toBe(1);
});

it('flags theoretical-vs-actual variance exactly', function () {
    PosCharge::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => 'posted',
        'business_date' => now()->toDateString(),
        'items' => [['menu_item_id' => $this->burger->id, 'name' => 'Burger', 'quantity' => 2, 'price' => 5000]],
        'subtotal' => 10000,
        'tax_amount' => 0,
        'total' => 10000,
    ]);

    // Unexplained extra flour usage (waste/theft signal).
    InventoryTransaction::create([
        'branch_id' => $this->branch->id,
        'inventory_item_id' => $this->flour->id,
        'type' => 'deduction',
        'quantity' => 2,
    ]);

    $report = $this->service->variance(
        $this->branch,
        now()->subDay()->toDateString(),
        now()->addDay()->toDateString(),
    );

    $flour = collect($report['rows'])->firstWhere('inventory_item_id', $this->flour->id);

    // Theoretical 0.4, actual 2 → variance 1.6 @100 = 160, flagged.
    expect($flour['theoretical_qty'])->toBe(0.4)
        ->and($flour['actual_qty'])->toBe(2.0)
        ->and($flour['variance_qty'])->toBe(1.6)
        ->and($flour['cost_value_minor'])->toBe(160)
        ->and($flour['flagged'])->toBeTrue();
});
