<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Models\YieldRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class YieldRuleTest extends TestCase
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

    public function test_can_list_yield_rules(): void
    {
        YieldRule::create([
            'branch_id' => $this->branch->id,
            'min_occupancy_pct' => 50,
            'max_occupancy_pct' => 70,
            'rate_multiplier' => 1.15,
        ]);

        YieldRule::create([
            'branch_id' => $this->branch->id,
            'min_occupancy_pct' => 70,
            'max_occupancy_pct' => 90,
            'rate_multiplier' => 1.30,
        ]);

        $response = $this->actingAs($this->user)->get('/yield-rules');

        $response->assertStatus(200);
    }

    public function test_can_create_yield_rule(): void
    {
        $response = $this->actingAs($this->user)->post('/yield-rules', [
            'min_occupancy_pct' => 50,
            'max_occupancy_pct' => 70,
            'rate_multiplier' => 1.15,
            'priority' => 1,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('yield_rules', [
            'branch_id' => $this->branch->id,
            'min_occupancy_pct' => 50,
            'max_occupancy_pct' => 70,
            'rate_multiplier' => 1.15,
        ]);
    }

    public function test_can_update_yield_rule(): void
    {
        $rule = YieldRule::create([
            'branch_id' => $this->branch->id,
            'min_occupancy_pct' => 50,
            'max_occupancy_pct' => 70,
            'rate_multiplier' => 1.15,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)->put("/yield-rules/{$rule->id}", [
            'rate_multiplier' => 1.50,
            'is_active' => false,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('yield_rules', [
            'id' => $rule->id,
            'rate_multiplier' => 1.50,
            'is_active' => false,
        ]);
    }

    public function test_can_delete_yield_rule(): void
    {
        $rule = YieldRule::create([
            'branch_id' => $this->branch->id,
            'min_occupancy_pct' => 50,
            'max_occupancy_pct' => 70,
            'rate_multiplier' => 1.15,
        ]);

        $response = $this->actingAs($this->user)->delete("/yield-rules/{$rule->id}");

        $response->assertRedirect();
        $this->assertSoftDeleted('yield_rules', ['id' => $rule->id]);
    }

    public function test_validates_required_fields(): void
    {
        $response = $this->actingAs($this->user)->post('/yield-rules', []);

        $response->assertSessionHasErrors([
            'min_occupancy_pct',
            'max_occupancy_pct',
            'rate_multiplier',
        ]);
    }

    public function test_validates_occupancy_range(): void
    {
        $response = $this->actingAs($this->user)->post('/yield-rules', [
            'min_occupancy_pct' => 80,
            'max_occupancy_pct' => 50,
            'rate_multiplier' => 1.15,
        ]);

        $response->assertSessionHasErrors(['max_occupancy_pct']);
    }
}
