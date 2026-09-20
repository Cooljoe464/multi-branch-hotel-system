<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DailyLedger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
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

    public function test_dashboard_returns_kpi_data(): void
    {
        DailyLedger::create([
            'branch_id' => $this->branch->id,
            'business_date' => now()->toDateString(),
            'status' => 'completed',
            'rooms_posted' => 10,
            'total_room_revenue' => 100000,
            'total_tax' => 10000,
            'total_other_charges' => 5000,
            'total_payments' => 80000,
            'net_revenue' => 35000,
        ]);

        $response = $this->actingAs($this->user)->get('/dashboard');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Dashboard')
            ->has('kpi')
            ->has('kpi.occupancy_pct')
            ->has('kpi.adr')
            ->has('kpi.revpar')
            ->has('kpi.revenue_30d')
            ->has('kpi.occupancy_trend_30d')
            ->has('kpi.room_type_performance'));
    }

    public function test_analytics_page_returns_full_data(): void
    {
        $response = $this->actingAs($this->user)->get('/analytics');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('analytics/Index')
            ->has('kpi')
            ->has('revenue')
            ->has('occupancyTrend')
            ->has('roomTypePerformance')
            ->has('days'));
    }

    public function test_analytics_respects_days_parameter(): void
    {
        $response = $this->actingAs($this->user)->get('/analytics?days=7');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->where('days', 7));
    }
}
