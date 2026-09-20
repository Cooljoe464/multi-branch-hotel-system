<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\KitchenWasteLog;
use App\Models\MenuItem;
use App\Models\User;
use App\Services\KitchenWasteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KitchenWasteServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected MenuItem $menuItem;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->menuItem = MenuItem::factory()->create([
            'branch_id' => $this->branch->id,
            'category' => 'food',
            'price' => 5000,
        ]);
        $this->user = User::factory()->create([
            'branch_id' => $this->branch->id,
        ]);
    }

    public function test_log_waste_creates_record(): void
    {
        $service = new KitchenWasteService;
        $log = $service->logWaste(
            $this->branch->id,
            $this->menuItem->id,
            'burned',
            3,
            15000,
            $this->user->id,
            'Burned during cooking'
        );

        $this->assertInstanceOf(KitchenWasteLog::class, $log);
        $this->assertEquals($this->branch->id, $log->branch_id);
        $this->assertEquals($this->menuItem->id, $log->menu_item_id);
        $this->assertEquals('burned', $log->reason);
        $this->assertEquals(3, $log->quantity);
        $this->assertEquals(15000, $log->cost);
        $this->assertEquals($this->user->id, $log->logged_by);
        $this->assertEquals('Burned during cooking', $log->notes);
    }

    public function test_log_waste_with_minimal_fields(): void
    {
        $service = new KitchenWasteService;
        $log = $service->logWaste(
            $this->branch->id,
            $this->menuItem->id,
            'expired',
            1,
            5000
        );

        $this->assertEquals('expired', $log->reason);
        $this->assertNull($log->logged_by);
        $this->assertNull($log->notes);
        $this->assertNull($log->kot_item_id);
    }
}
