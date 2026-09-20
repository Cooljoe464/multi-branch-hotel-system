<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\RateOverride;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\YieldRule;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingServiceTest extends TestCase
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

    public function test_returns_base_rate_when_no_overrides(): void
    {
        $service = (new PricingService)->forBranch($this->branch);

        $rate = $service->getEffectiveRate($this->roomType, now());

        $this->assertEquals(10000, $rate);
    }

    public function test_applies_rate_override(): void
    {
        RateOverride::create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'rate_override' => 15000,
            'is_active' => true,
        ]);

        $service = (new PricingService)->forBranch($this->branch);
        $rate = $service->getEffectiveRate($this->roomType, now());

        $this->assertEquals(15000, $rate);
    }

    public function test_applies_yield_rule_multiplier(): void
    {
        YieldRule::create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'min_occupancy_pct' => 0,
            'max_occupancy_pct' => 100,
            'rate_multiplier' => 1.25,
            'is_active' => true,
            'priority' => 1,
        ]);

        $service = (new PricingService)->forBranch($this->branch);

        $rate = $service->getEffectiveRate($this->roomType, now());

        // Base rate * multiplier = 10000 * 1.25 = 12500
        $this->assertEquals(12500, $rate);
    }

    public function test_calculates_total_for_stay(): void
    {
        $service = (new PricingService)->forBranch($this->branch);

        $checkIn = now()->addDay();
        $checkOut = now()->addDays(4);

        $result = $service->calculateTotal($this->roomType, $checkIn, $checkOut);

        $this->assertEquals(3, $result['nights']);
        $this->assertEquals(30000, $result['total']); // 10000 * 3 nights
        $this->assertTrue($result['mlos_met']);
        $this->assertFalse($result['cta_violated']);
    }

    public function test_detects_cta_violation(): void
    {
        RateOverride::create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'cta' => true,
            'is_active' => true,
        ]);

        $service = (new PricingService)->forBranch($this->branch);

        $checkIn = now()->addDay();
        $checkOut = now()->addDays(3);

        $result = $service->calculateTotal($this->roomType, $checkIn, $checkOut);

        $this->assertTrue($result['cta_violated']);
    }

    public function test_detects_mlos_violation(): void
    {
        RateOverride::create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'mlos' => 3,
            'is_active' => true,
        ]);

        $service = (new PricingService)->forBranch($this->branch);

        $checkIn = now()->addDay();
        $checkOut = now()->addDays(2); // 2 nights < 3 MLOS

        $result = $service->calculateTotal($this->roomType, $checkIn, $checkOut);

        $this->assertFalse($result['mlos_met']);
        $this->assertEquals(3, $result['mlos']);
    }

    public function test_get_stay_constraints(): void
    {
        RateOverride::create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'mlos' => 2,
            'cta' => true,
            'ctd' => false,
            'is_active' => true,
        ]);

        $service = (new PricingService)->forBranch($this->branch);
        $constraints = $service->getStayConstraints($this->roomType, now());

        $this->assertEquals(2, $constraints['mlos']);
        $this->assertTrue($constraints['cta']);
        $this->assertFalse($constraints['ctd']);
    }

    public function test_calculates_occupancy_percentage(): void
    {
        Room::factory()->count(10)->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'is_active' => true,
            'status' => 'available',
        ]);

        $service = (new PricingService)->forBranch($this->branch);
        $occupancy = $service->getOccupancyPercentage($this->roomType, now());

        // No checked-in reservations, so occupancy should be 0
        $this->assertEquals(0, $occupancy);
    }

    public function test_get_available_room_types_returns_results_with_room_type_id(): void
    {
        Room::factory()->count(3)->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'status' => 'available',
        ]);

        $service = new PricingService;
        $results = $service->getAvailableRoomTypesForSearch(
            $this->branch->id,
            now()->addDay()->toDateString(),
            now()->addDays(3)->toDateString(),
            2,
        );

        $this->assertNotEmpty($results);
        $this->assertArrayHasKey('room_type_id', $results->first());
        $this->assertEquals($this->roomType->id, $results->first()['room_type_id']);
    }

    public function test_get_available_room_types_excludes_unavailable_room_types(): void
    {
        // All rooms of this type are out of order
        Room::factory()->count(2)->outOfOrder()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
        ]);

        $service = new PricingService;
        $results = $service->getAvailableRoomTypesForSearch(
            $this->branch->id,
            now()->addDay()->toDateString(),
            now()->addDays(3)->toDateString(),
            2,
        );

        $this->assertTrue($results->isEmpty());
    }

    public function test_get_available_room_types_excludes_types_exceeding_occupancy(): void
    {
        Room::factory()->count(2)->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'status' => 'available',
        ]);

        $service = new PricingService;
        $results = $service->getAvailableRoomTypesForSearch(
            $this->branch->id,
            now()->addDay()->toDateString(),
            now()->addDays(3)->toDateString(),
            10, // exceeds max_occupancy of 2
        );

        $this->assertTrue($results->isEmpty());
    }

    public function test_get_available_room_types_excludes_overlapping_reservations(): void
    {
        $room = Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'status' => 'available',
        ]);

        Reservation::factory()->checkedIn()->create([
            'branch_id' => $this->branch->id,
            'room_id' => $room->id,
            'room_type_id' => $this->roomType->id,
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(5)->toDateString(),
        ]);

        $service = new PricingService;
        $results = $service->getAvailableRoomTypesForSearch(
            $this->branch->id,
            now()->addDays(2)->toDateString(),
            now()->addDays(4)->toDateString(),
            2,
        );

        $this->assertTrue($results->isEmpty());
    }

    public function test_combined_override_and_yield_rule_applies_both(): void
    {
        RateOverride::create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'rate_override' => 20000,
            'is_active' => true,
        ]);

        YieldRule::create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'min_occupancy_pct' => 0,
            'max_occupancy_pct' => 100,
            'rate_multiplier' => 1.5,
            'is_active' => true,
            'priority' => 1,
        ]);

        $service = (new PricingService)->forBranch($this->branch);
        $rate = $service->getEffectiveRate($this->roomType, now());

        // Override 20000 * multiplier 1.5 = 30000
        $this->assertEquals(30000, $rate);
    }

    public function test_yield_rule_mlos_overrides_rate_override_mlos(): void
    {
        RateOverride::create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'mlos' => 2,
            'is_active' => true,
        ]);

        YieldRule::create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'min_occupancy_pct' => 0,
            'max_occupancy_pct' => 100,
            'rate_multiplier' => 1.0,
            'mlos_override' => 5,
            'is_active' => true,
            'priority' => 1,
        ]);

        $service = (new PricingService)->forBranch($this->branch);
        $constraints = $service->getStayConstraints($this->roomType, now());

        $this->assertEquals(5, $constraints['mlos']);
    }

    public function test_yield_rule_cta_override_applies(): void
    {
        YieldRule::create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'min_occupancy_pct' => 0,
            'max_occupancy_pct' => 100,
            'rate_multiplier' => 1.0,
            'cta_override' => true,
            'is_active' => true,
            'priority' => 1,
        ]);

        $service = (new PricingService)->forBranch($this->branch);
        $constraints = $service->getStayConstraints($this->roomType, now());

        $this->assertTrue($constraints['cta']);
    }

    public function test_occupancy_percentage_with_checked_in_reservations(): void
    {
        Room::factory()->count(10)->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'status' => 'available',
        ]);

        $room = Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'status' => 'occupied',
        ]);

        Reservation::factory()->checkedIn()->create([
            'branch_id' => $this->branch->id,
            'room_id' => $room->id,
            'room_type_id' => $this->roomType->id,
            'check_in_date' => now()->subDay()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
        ]);

        $service = (new PricingService)->forBranch($this->branch);
        $occupancy = $service->getOccupancyPercentage($this->roomType, now());

        // 1 occupied out of 11 total rooms = ~9%
        $this->assertEqualsWithDelta(9, $occupancy, 1);
    }
}
