<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TapeChartTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->user = User::factory()->create(['branch_id' => $this->branch->id]);
    }

    public function test_tape_chart_route_exists(): void
    {
        $response = $this->actingAs($this->user)->get('/tape-chart');

        $response->assertStatus(200);
    }
}
