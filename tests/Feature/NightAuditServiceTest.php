<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DailyLedger;
use App\Models\Folio;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\NightAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class NightAuditServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected RoomType $roomType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create([
            'tax_rate' => 10.0,
        ]);
        $this->roomType = RoomType::factory()->create([
            'branch_id' => $this->branch->id,
            'base_rate' => 10000,
        ]);
    }

    public function test_throws_logic_exception_when_branch_not_set(): void
    {
        $service = new NightAuditService;
        $this->expectException(\LogicException::class);
        $service->postRoomCharges(Carbon::now());
    }

    public function test_post_room_charges_for_checked_in_reservations(): void
    {
        $room = Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'number' => '101',
        ]);

        $reservation = Reservation::factory()->checkedIn()->create([
            'branch_id' => $this->branch->id,
            'room_id' => $room->id,
            'room_type_id' => $this->roomType->id,
            'room_rate' => 15000,
            'check_in_date' => now()->subDay()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
        ]);

        Folio::create([
            'branch_id' => $this->branch->id,
            'reservation_id' => $reservation->id,
            'folio_number' => 'FOL-TEST-001',
            'type' => 'individual',
            'status' => 'open',
            'balance' => 0,
        ]);

        $service = (new NightAuditService)->forBranch($this->branch);
        $result = $service->postRoomCharges(Carbon::now());

        $this->assertEquals(1, $result['posted']);
        $this->assertEquals(15000, $result['total_room_revenue']);
        $this->assertEmpty($result['errors']);
    }

    public function test_creates_folio_if_none_exists(): void
    {
        $room = Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
        ]);

        Reservation::factory()->checkedIn()->create([
            'branch_id' => $this->branch->id,
            'room_id' => $room->id,
            'room_type_id' => $this->roomType->id,
            'room_rate' => 10000,
            'check_in_date' => now()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
        ]);

        $service = (new NightAuditService)->forBranch($this->branch);
        $result = $service->postRoomCharges(Carbon::now());

        $this->assertEquals(1, $result['posted']);
        $this->assertDatabaseHas(Folio::class, [
            'branch_id' => $this->branch->id,
            'type' => 'individual',
            'status' => 'open',
        ]);
    }

    public function test_calculates_tax_correctly(): void
    {
        $room = Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
        ]);

        $reservation = Reservation::factory()->checkedIn()->create([
            'branch_id' => $this->branch->id,
            'room_id' => $room->id,
            'room_type_id' => $this->roomType->id,
            'room_rate' => 10000,
            'check_in_date' => now()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
        ]);

        Folio::create([
            'branch_id' => $this->branch->id,
            'reservation_id' => $reservation->id,
            'folio_number' => 'FOL-TAX-001',
            'type' => 'individual',
            'status' => 'open',
            'balance' => 0,
        ]);

        $service = (new NightAuditService)->forBranch($this->branch);
        $result = $service->postRoomCharges(Carbon::now());

        // tax_rate = 10.0, taxRateBps = 1000
        // taxAmount = round(10000 * 1000 / 10000) = 1000
        $this->assertEquals(1000, $result['total_tax']);
    }

    public function test_handles_no_checked_in_reservations(): void
    {
        $service = (new NightAuditService)->forBranch($this->branch);
        $result = $service->postRoomCharges(Carbon::now());

        $this->assertEquals(0, $result['posted']);
        $this->assertEquals(0, $result['total_room_revenue']);
        $this->assertEquals(0, $result['total_tax']);
    }

    public function test_close_daily_ledger_marks_completed(): void
    {
        $service = (new NightAuditService)->forBranch($this->branch);
        $ledger = $service->closeDailyLedger(Carbon::now());

        $this->assertEquals('completed', $ledger->fresh()->status);
        $this->assertNotNull($ledger->fresh()->completed_at);
    }

    public function test_close_daily_ledger_is_idempotent(): void
    {
        $service = (new NightAuditService)->forBranch($this->branch);
        $first = $service->closeDailyLedger(Carbon::now());
        $second = $service->closeDailyLedger(Carbon::now());

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals('completed', $second->fresh()->status);
    }

    public function test_close_daily_ledger_returns_existing_in_progress(): void
    {
        $ledger = DailyLedger::create([
            'branch_id' => $this->branch->id,
            'business_date' => now()->toDateString(),
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        $service = (new NightAuditService)->forBranch($this->branch);
        $result = $service->closeDailyLedger(Carbon::now());

        $this->assertEquals($ledger->id, $result->id);
    }

    public function test_close_daily_ledger_calculates_net_revenue(): void
    {
        $room = Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
        ]);

        $reservation = Reservation::factory()->checkedIn()->create([
            'branch_id' => $this->branch->id,
            'room_id' => $room->id,
            'room_type_id' => $this->roomType->id,
            'room_rate' => 10000,
            'check_in_date' => now()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
        ]);

        Folio::create([
            'branch_id' => $this->branch->id,
            'reservation_id' => $reservation->id,
            'folio_number' => 'FOL-NET-001',
            'type' => 'individual',
            'status' => 'open',
            'balance' => 0,
        ]);

        $service = (new NightAuditService)->forBranch($this->branch);
        $ledger = $service->closeDailyLedger(Carbon::now());

        // room_revenue = 10000, tax = 1000, other_charges = 0, payments = 0
        $this->assertEquals(10000, $ledger->fresh()->total_room_revenue);
        $this->assertEquals(1000, $ledger->fresh()->total_tax);
        // net_revenue = 10000 + 1000 + 0 - 0 = 11000
        $this->assertEquals(11000, $ledger->fresh()->net_revenue);
    }
}
