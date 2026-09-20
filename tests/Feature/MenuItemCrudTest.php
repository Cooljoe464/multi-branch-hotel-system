<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\MenuItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuItemTest extends TestCase
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

    public function test_can_list_menu_items(): void
    {
        MenuItem::factory()->count(3)->forBranch($this->branch->id)->create();
        $response = $this->actingAs($this->user)->get('/menu-items');
        $response->assertStatus(200);
    }

    public function test_can_create_menu_item(): void
    {
        $response = $this->actingAs($this->user)->post('/menu-items', [
            'category' => 'food',
            'name' => 'Grilled Chicken',
            'price' => 4500,
        ]);
        $response->assertRedirect();
        $this->assertDatabaseHas('menu_items', ['name' => 'Grilled Chicken', 'branch_id' => $this->branch->id]);
    }

    public function test_can_update_menu_item(): void
    {
        $item = MenuItem::factory()->forBranch($this->branch->id)->create();
        $response = $this->actingAs($this->user)->put("/menu-items/{$item->id}", ['name' => 'Updated', 'price' => 5000]);
        $response->assertRedirect();
        $this->assertDatabaseHas('menu_items', ['id' => $item->id, 'name' => 'Updated', 'price' => 5000]);
    }

    public function test_can_delete_menu_item(): void
    {
        $item = MenuItem::factory()->forBranch($this->branch->id)->create();
        $response = $this->actingAs($this->user)->delete("/menu-items/{$item->id}");
        $response->assertRedirect();
        $this->assertSoftDeleted('menu_items', ['id' => $item->id]);
    }

    public function test_cannot_create_with_invalid_category(): void
    {
        $response = $this->actingAs($this->user)->post('/menu-items', ['category' => 'invalid', 'name' => 'Test', 'price' => 1000]);
        $response->assertSessionHasErrors('category');
    }
}
