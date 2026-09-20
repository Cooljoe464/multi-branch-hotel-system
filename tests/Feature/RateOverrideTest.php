<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\RateOverride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RateOverrideTest extends TestCase
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

    public function test_can_list_rate_overrides(): void
    {
        RateOverride::create([
            'branch_id' => $this->branch->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
            'rate_override' => 15000,
        ]);

        $response = $this->actingAs($this->user)->get('/rate-overrides');

        $response->assertStatus(200);
    }

    public function test_can_create_rate_override(): void
    {
        $response = $this->actingAs($this->user)->post('/rate-overrides', [
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
            'rate_override' => 15000,
            'mlos' => 2,
            'cta' => false,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('rate_overrides', [
            'branch_id' => $this->branch->id,
            'rate_override' => 15000,
            'mlos' => 2,
        ]);
    }

    public function test_can_update_rate_override(): void
    {
        $override = RateOverride::create([
            'branch_id' => $this->branch->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
            'rate_override' => 15000,
        ]);

        $response = $this->actingAs($this->user)->put("/rate-overrides/{$override->id}", [
            'rate_override' => 20000,
            'cta' => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('rate_overrides', [
            'id' => $override->id,
            'rate_override' => 20000,
            'cta' => true,
        ]);
    }

    public function test_can_delete_rate_override(): void
    {
        $override = RateOverride::create([
            'branch_id' => $this->branch->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
            'rate_override' => 15000,
        ]);

        $response = $this->actingAs($this->user)->delete("/rate-overrides/{$override->id}");

        $response->assertRedirect();
        $this->assertSoftDeleted('rate_overrides', ['id' => $override->id]);
    }

    public function test_validates_required_fields(): void
    {
        $response = $this->actingAs($this->user)->post('/rate-overrides', []);

        $response->assertSessionHasErrors([
            'start_date',
            'end_date',
        ]);
    }

    public function test_validates_end_date_after_start_date(): void
    {
        $response = $this->actingAs($this->user)->post('/rate-overrides', [
            'start_date' => now()->addDays(10)->toDateString(),
            'end_date' => now()->toDateString(),
        ]);

        $response->assertSessionHasErrors(['end_date']);
    }
}
