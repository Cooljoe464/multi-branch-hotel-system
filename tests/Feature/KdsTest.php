<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\KotItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KdsTest extends TestCase
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

    public function test_can_view_kds_queue(): void
    {
        $response = $this->actingAs($this->user)->get('/kds');
        $response->assertStatus(200);
    }

    public function test_can_update_kot_item_status(): void
    {
        $kotItem = KotItem::factory()->forBranch($this->branch->id)->create(['status' => 'pending']);
        $response = $this->actingAs($this->user)->patch("/kds/items/{$kotItem->id}/status", ['status' => 'preparing']);
        $response->assertRedirect();
        $this->assertDatabaseHas('kot_items', ['id' => $kotItem->id, 'status' => 'preparing']);
    }
}
