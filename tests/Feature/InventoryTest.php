<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::factory()->create();
        $this->user = $this->makeAdminUser($this->branch);
    }

    public function test_can_list_inventory(): void
    {
        InventoryItem::factory()->count(3)->forBranch($this->branch->id)->create();
        $response = $this->actingAs($this->user)->get('/inventory');
        $response->assertStatus(200);
    }

    public function test_can_create_inventory_item(): void
    {
        $response = $this->actingAs($this->user)->post('/inventory', [
            'name' => 'Chicken Breast',
            'category' => 'food',
            'unit' => 'kg',
            'current_quantity' => 50,
            'reorder_point' => 10,
            'cost_per_unit' => 2500,
        ]);
        $response->assertRedirect();
        $this->assertDatabaseHas('inventory_items', ['name' => 'Chicken Breast', 'branch_id' => $this->branch->id]);
    }

    public function test_can_restock_inventory(): void
    {
        $item = InventoryItem::factory()->forBranch($this->branch->id)->create(['current_quantity' => 10]);
        $response = $this->actingAs($this->user)->post("/inventory/{$item->id}/restock", ['quantity' => 20]);
        $response->assertRedirect();
        $this->assertDatabaseHas('inventory_items', ['id' => $item->id, 'current_quantity' => 30]);
    }

    public function test_can_delete_inventory_item(): void
    {
        $item = InventoryItem::factory()->forBranch($this->branch->id)->create();
        $response = $this->actingAs($this->user)->delete("/inventory/{$item->id}");
        $response->assertRedirect();
        $this->assertSoftDeleted('inventory_items', ['id' => $item->id]);
    }
}
