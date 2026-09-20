<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DailyLedger;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\FolioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class FinancialEdgeCaseTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
    }

    public function test_tax_calculation_with_fractional_cents_rounds_correctly(): void
    {
        $reservation = Reservation::factory()->checkedIn()->create([
            'branch_id' => $this->branch->id,
            'room_rate' => 10000,
        ]);

        $service = new FolioService;
        $folio = $service->createFolio($this->branch->id, $reservation->id);

        $service->postDebit($folio, 'room_rate', 'Night 1', 10001, null, 750);

        $transaction = $folio->transactions()->where('category', 'room_rate')->first();

        $this->assertEquals(750, $transaction->tax_amount);
    }

    public function test_debit_with_zero_amount_creates_transaction(): void
    {
        $reservation = Reservation::factory()->checkedIn()->create([
            'branch_id' => $this->branch->id,
            'room_rate' => 10000,
        ]);

        $service = new FolioService;
        $folio = $service->createFolio($this->branch->id, $reservation->id);

        $transaction = $service->postDebit($folio, 'adjustment', 'Zero adjustment', 0);

        $this->assertEquals(0, $transaction->amount);
        $folio->refresh();
        $this->assertEquals(0, $folio->balance);
    }

    public function test_credit_exceeding_debits_results_in_negative_folio_balance(): void
    {
        $reservation = Reservation::factory()->checkedIn()->create([
            'branch_id' => $this->branch->id,
            'room_rate' => 10000,
        ]);

        $service = new FolioService;
        $folio = $service->createFolio($this->branch->id, $reservation->id);

        $service->postDebit($folio, 'room_rate', 'Night 1', 10000);
        $service->postCredit($folio, 'payment', 'Overpayment', 15000);

        $folio->refresh();
        $this->assertEquals(-5000, $folio->balance);
    }

    public function test_concurrent_debit_postings_maintain_balance_integrity(): void
    {
        $reservation = Reservation::factory()->checkedIn()->create([
            'branch_id' => $this->branch->id,
            'room_rate' => 10000,
        ]);

        $service = new FolioService;
        $folio = $service->createFolio($this->branch->id, $reservation->id);

        $service->postDebit($folio, 'room_rate', 'Night 1', 10000);
        $service->postDebit($folio, 'minibar', 'Snacks', 1500);
        $service->postDebit($folio, 'restaurant', 'Dinner', 3000);
        $service->postDebit($folio, 'spa', 'Massage', 5000);

        $folio->refresh();
        $this->assertEquals(19500, $folio->balance);
    }

    public function test_transfer_charge_preserves_total_across_folios(): void
    {
        $reservation = Reservation::factory()->checkedIn()->create([
            'branch_id' => $this->branch->id,
            'room_rate' => 10000,
        ]);

        $service = new FolioService;
        $master = $service->createFolio($this->branch->id, null, null, 'Master');
        $child = $service->createFolio($this->branch->id, $reservation->id, $master->id);

        $debit = $service->postDebit($master, 'restaurant', 'Dinner', 3000);
        $service->postDebit($master, 'minibar', 'Snacks', 1500);

        $service->transferCharge($debit, $child);

        $master->refresh();
        $child->refresh();

        $this->assertEquals(1500, $master->balance);
        $this->assertEquals(3000, $child->balance);
    }

    public function test_void_transaction_recalculates_balance(): void
    {
        $reservation = Reservation::factory()->checkedIn()->create([
            'branch_id' => $this->branch->id,
            'room_rate' => 10000,
        ]);

        $service = new FolioService;
        $folio = $service->createFolio($this->branch->id, $reservation->id);

        $txn1 = $service->postDebit($folio, 'room_rate', 'Night 1', 10000);
        $service->postDebit($folio, 'restaurant', 'Dinner', 3000);
        $service->postCredit($folio, 'payment', 'Partial', 5000);

        $txn1->void();

        $folio->refresh();
        $this->assertEquals(-2000, $folio->balance);
    }

    public function test_night_audit_is_idempotent_for_same_business_date(): void
    {
        $roomType = RoomType::factory()->create(['branch_id' => $this->branch->id]);
        $room = Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $roomType->id,
            'status' => 'occupied',
        ]);

        $yesterday = now()->subDay()->toDateString();

        Reservation::factory()->checkedIn()->create([
            'branch_id' => $this->branch->id,
            'room_id' => $room->id,
            'room_type_id' => $roomType->id,
            'room_rate' => 10000,
            'check_in_date' => $yesterday,
            'check_out_date' => now()->addDays(3)->toDateString(),
        ]);

        Artisan::call('night-audit', ['--branch' => $this->branch->id, '--date' => $yesterday]);
        $count1 = DailyLedger::where('branch_id', $this->branch->id)->count();

        Artisan::call('night-audit', ['--branch' => $this->branch->id, '--date' => $yesterday]);
        $count2 = DailyLedger::where('branch_id', $this->branch->id)->count();

        $this->assertEquals($count1, $count2);
    }

    public function test_night_audit_creates_folio_when_missing(): void
    {
        $roomType = RoomType::factory()->create(['branch_id' => $this->branch->id]);
        $room = Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $roomType->id,
            'status' => 'occupied',
        ]);

        $yesterday = now()->subDay()->toDateString();

        $reservation = Reservation::factory()->checkedIn()->create([
            'branch_id' => $this->branch->id,
            'room_id' => $room->id,
            'room_type_id' => $roomType->id,
            'room_rate' => 10000,
            'check_in_date' => $yesterday,
            'check_out_date' => now()->addDays(3)->toDateString(),
        ]);

        $this->assertDatabaseMissing('folios', [
            'reservation_id' => $reservation->id,
        ]);

        Artisan::call('night-audit', ['--branch' => $this->branch->id, '--date' => $yesterday]);

        $this->assertDatabaseHas('folios', [
            'reservation_id' => $reservation->id,
            'branch_id' => $this->branch->id,
        ]);
    }
}
