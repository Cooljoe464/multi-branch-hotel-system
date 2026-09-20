<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutletTest extends TestCase
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

    public function test_can_list_outlets(): void
    {
        Outlet::factory()->count(3)->forBranch($this->branch->id)->create();
        $response = $this->actingAs($this->user)->get('/outlets');
        $response->assertStatus(200);
    }

    public function test_can_create_outlet(): void
    {
        $response = $this->actingAs($this->user)->post('/outlets', [
            'name' => 'Main Restaurant',
            'code' => 'REST-01',
            'type' => 'restaurant',
        ]);
        $response->assertRedirect();
        $this->assertDatabaseHas('outlets', ['name' => 'Main Restaurant', 'branch_id' => $this->branch->id]);
    }

    public function test_can_update_outlet(): void
    {
        $outlet = Outlet::factory()->forBranch($this->branch->id)->create();
        $response = $this->actingAs($this->user)->put("/outlets/{$outlet->id}", ['name' => 'Updated']);
        $response->assertRedirect();
        $this->assertDatabaseHas('outlets', ['id' => $outlet->id, 'name' => 'Updated']);
    }

    public function test_can_delete_outlet(): void
    {
        $outlet = Outlet::factory()->forBranch($this->branch->id)->create();
        $response = $this->actingAs($this->user)->delete("/outlets/{$outlet->id}");
        $response->assertRedirect();
        $this->assertSoftDeleted('outlets', ['id' => $outlet->id]);
    }
}
