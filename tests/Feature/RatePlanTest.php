<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\RatePlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RatePlanTest extends TestCase
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

    public function test_can_list_rate_plans(): void
    {
        RatePlan::factory()->count(3)->forBranch($this->branch->id)->create();
        $response = $this->actingAs($this->user)->get('/rate-plans');
        $response->assertStatus(200);
    }

    public function test_can_create_rate_plan(): void
    {
        $response = $this->actingAs($this->user)->post('/rate-plans', [
            'name' => 'BAR',
            'code' => 'BAR-01',
            'type' => 'bar',
            'rate_multiplier' => 1.00,
            'valid_from' => now()->toDateString(),
        ]);
        $response->assertRedirect();
        $this->assertDatabaseHas('rate_plans', ['code' => 'BAR-01', 'branch_id' => $this->branch->id]);
    }

    public function test_can_update_rate_plan(): void
    {
        $plan = RatePlan::factory()->forBranch($this->branch->id)->create();
        $response = $this->actingAs($this->user)->put("/rate-plans/{$plan->id}", ['name' => 'Updated', 'rate_multiplier' => 1.5]);
        $response->assertRedirect();
        $this->assertDatabaseHas('rate_plans', ['id' => $plan->id, 'name' => 'Updated', 'rate_multiplier' => 1.5]);
    }

    public function test_can_delete_rate_plan(): void
    {
        $plan = RatePlan::factory()->forBranch($this->branch->id)->create();
        $response = $this->actingAs($this->user)->delete("/rate-plans/{$plan->id}");
        $response->assertRedirect();
        $this->assertSoftDeleted('rate_plans', ['id' => $plan->id]);
    }
}
