<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DailyLedger;
use App\Models\Room;
use App\Services\AnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
    }

    public function test_calculates_occupancy_percentage(): void
    {
        Room::factory()->count(10)->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
            'status' => 'available',
        ]);

        $service = (new AnalyticsService)->forBranch($this->branch);
        $occupancy = $service->getOccupancyPercentage();

        $this->assertIsFloat($occupancy);
        $this->assertGreaterThanOrEqual(0, $occupancy);
        $this->assertLessThanOrEqual(100, $occupancy);
    }

    public function test_calculates_average_daily_rate(): void
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

        $service = (new AnalyticsService)->forBranch($this->branch);
        $adr = $service->getAverageDailyRate();

        // 100000 / 10 = 10000
        $this->assertEquals(10000, $adr);
    }

    public function test_calculates_revpar(): void
    {
        Room::factory()->count(20)->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
            'status' => 'available',
        ]);

        DailyLedger::create([
            'branch_id' => $this->branch->id,
            'business_date' => now()->toDateString(),
            'status' => 'completed',
            'rooms_posted' => 15,
            'total_room_revenue' => 150000,
            'total_tax' => 15000,
            'total_other_charges' => 5000,
            'total_payments' => 120000,
            'net_revenue' => 50000,
        ]);

        $service = (new AnalyticsService)->forBranch($this->branch);
        $revpar = $service->getRevPAR();

        // 150000 / 20 = 7500
        $this->assertEquals(7500, $revpar);
    }

    public function test_gets_revenue_summary(): void
    {
        DailyLedger::create([
            'branch_id' => $this->branch->id,
            'business_date' => now()->subDay()->toDateString(),
            'status' => 'completed',
            'rooms_posted' => 10,
            'total_room_revenue' => 100000,
            'total_tax' => 10000,
            'total_other_charges' => 5000,
            'total_payments' => 80000,
            'net_revenue' => 35000,
        ]);

        DailyLedger::create([
            'branch_id' => $this->branch->id,
            'business_date' => now()->toDateString(),
            'status' => 'completed',
            'rooms_posted' => 12,
            'total_room_revenue' => 120000,
            'total_tax' => 12000,
            'total_other_charges' => 6000,
            'total_payments' => 95000,
            'net_revenue' => 43000,
        ]);

        $service = (new AnalyticsService)->forBranch($this->branch);
        $summary = $service->getRevenueSummary(7);

        $this->assertEquals(220000, $summary['total_room_revenue']);
        $this->assertEquals(22000, $summary['total_tax']);
        $this->assertEquals(78000, $summary['net_revenue']);
        $this->assertCount(2, $summary['daily']);
    }

    public function test_gets_kpi_summary(): void
    {
        Room::factory()->count(10)->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
            'status' => 'available',
        ]);

        $service = (new AnalyticsService)->forBranch($this->branch);
        $kpi = $service->getKpiSummary();

        $this->assertArrayHasKey('date', $kpi);
        $this->assertArrayHasKey('occupancy_pct', $kpi);
        $this->assertArrayHasKey('adr', $kpi);
        $this->assertArrayHasKey('revpar', $kpi);
        $this->assertArrayHasKey('revenue_7d', $kpi);
        $this->assertArrayHasKey('revenue_30d', $kpi);
        $this->assertArrayHasKey('occupancy_trend_30d', $kpi);
        $this->assertArrayHasKey('room_type_performance', $kpi);
    }

    public function test_returns_zero_when_no_rooms(): void
    {
        $service = (new AnalyticsService)->forBranch($this->branch);

        $this->assertEquals(0, $service->getOccupancyPercentage());
        $this->assertEquals(0, $service->getAverageDailyRate());
        $this->assertEquals(0, $service->getRevPAR());
    }
}
