<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\YieldRule;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingServiceRatePlanTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected RoomType $roomType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->roomType = RoomType::factory()->create([
            'branch_id' => $this->branch->id,
            'base_rate' => 10000,
        ]);
    }

    public function test_applies_rate_plan_multiplier(): void
    {
        $ratePlan = RatePlan::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'rate_multiplier' => 1.5,
            'is_active' => true,
            'valid_from' => now()->subDay()->toDateString(),
            'valid_to' => now()->addMonth()->toDateString(),
        ]);

        $service = (new PricingService)->forBranch($this->branch);
        $rate = $service->getEffectiveRate($this->roomType, now(), $ratePlan);

        $this->assertEquals(15000, $rate);
    }

    public function test_enforces_rate_plan_min_rate(): void
    {
        $ratePlan = RatePlan::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'rate_multiplier' => 0.5,
            'min_rate' => 8000,
            'is_active' => true,
            'valid_from' => now()->subDay()->toDateString(),
            'valid_to' => now()->addMonth()->toDateString(),
        ]);

        $service = (new PricingService)->forBranch($this->branch);
        $rate = $service->getEffectiveRate($this->roomType, now(), $ratePlan);

        $this->assertEquals(8000, $rate);
    }

    public function test_enforces_rate_plan_max_rate(): void
    {
        $ratePlan = RatePlan::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'rate_multiplier' => 3.0,
            'max_rate' => 20000,
            'is_active' => true,
            'valid_from' => now()->subDay()->toDateString(),
            'valid_to' => now()->addMonth()->toDateString(),
        ]);

        $service = (new PricingService)->forBranch($this->branch);
        $rate = $service->getEffectiveRate($this->roomType, now(), $ratePlan);

        $this->assertEquals(20000, $rate);
    }

    public function test_ignores_rate_plan_outside_validity_window(): void
    {
        $ratePlan = RatePlan::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'rate_multiplier' => 2.0,
            'is_active' => true,
            'valid_from' => now()->addMonth()->toDateString(),
            'valid_to' => now()->addMonths(2)->toDateString(),
        ]);

        $service = (new PricingService)->forBranch($this->branch);
        $rate = $service->getEffectiveRate($this->roomType, now(), $ratePlan);

        $this->assertEquals(10000, $rate);
    }

    public function test_ignores_inactive_rate_plan(): void
    {
        $ratePlan = RatePlan::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'rate_multiplier' => 2.0,
            'is_active' => false,
            'valid_from' => now()->subDay()->toDateString(),
            'valid_to' => now()->addMonth()->toDateString(),
        ]);

        $service = (new PricingService)->forBranch($this->branch);
        $rate = $service->getEffectiveRate($this->roomType, now(), $ratePlan);

        $this->assertEquals(10000, $rate);
    }

    public function test_rate_plan_works_without_optional_param(): void
    {
        $service = (new PricingService)->forBranch($this->branch);
        $rate = $service->getEffectiveRate($this->roomType, now());

        $this->assertEquals(10000, $rate);
    }

    public function test_rate_plan_combined_with_yield_rule(): void
    {
        $ratePlan = RatePlan::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'rate_multiplier' => 1.5,
            'is_active' => true,
            'valid_from' => now()->subDay()->toDateString(),
            'valid_to' => now()->addMonth()->toDateString(),
        ]);

        YieldRule::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'min_occupancy_pct' => 0,
            'max_occupancy_pct' => 50,
            'rate_multiplier' => 1.2,
            'is_active' => true,
        ]);

        $service = (new PricingService)->forBranch($this->branch);
        $rate = $service->getEffectiveRate($this->roomType, now(), $ratePlan);

        // base_rate(10000) * ratePlan(1.5) = 15000, then * yield(1.2) = 18000
        $this->assertEquals(18000, $rate);
    }
}
