<?php

use App\Models\Branch;
use App\Models\DailyLedger;
use App\Models\Folio;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['tax_rate' => 7.5]);
    $this->roomType = RoomType::factory()->create(['branch_id' => $this->branch->id]);
});

it('posts room charges for checked-in reservations', function () {
    $room = Room::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'status' => 'occupied',
    ]);

    $yesterday = now()->subDay()->toDateString();

    $reservation = Reservation::factory()->checkedIn()->create([
        'branch_id' => $this->branch->id,
        'room_id' => $room->id,
        'room_type_id' => $this->roomType->id,
        'room_rate' => 15000,
        'check_in_date' => $yesterday,
        'check_out_date' => now()->addDays(3)->toDateString(),
    ]);

    Artisan::call('night-audit', ['--branch' => $this->branch->id, '--date' => $yesterday]);

    $output = Artisan::output();
    expect($output)->toContain('Rooms posted: 1');

    $this->assertDatabaseHas('daily_ledgers', [
        'branch_id' => $this->branch->id,
        'status' => 'completed',
    ]);

    $this->assertDatabaseHas('transactions', [
        'category' => 'room_rate',
    ]);
});

it('creates a daily ledger for the branch', function () {
    $yesterday = now()->subDay()->toDateString();

    Artisan::call('night-audit', ['--branch' => $this->branch->id, '--date' => $yesterday]);

    $this->assertDatabaseHas('daily_ledgers', [
        'branch_id' => $this->branch->id,
        'business_date' => $yesterday,
        'status' => 'completed',
    ]);
});

it('does not re-run completed audit', function () {
    $yesterday = now()->subDay()->toDateString();

    DailyLedger::create([
        'branch_id' => $this->branch->id,
        'business_date' => $yesterday,
        'status' => 'completed',
        'rooms_posted' => 5,
        'total_room_revenue' => 50000,
        'total_tax' => 3750,
        'completed_at' => now(),
    ]);

    Artisan::call('night-audit', ['--branch' => $this->branch->id, '--date' => $yesterday]);

    $output = Artisan::output();
    expect($output)->toContain('Already completed');
});

it('processes all active branches when no branch specified', function () {
    $branch2 = Branch::factory()->create(['is_active' => true]);
    $yesterday = now()->subDay()->toDateString();

    $room1 = Room::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'status' => 'occupied',
    ]);
    $roomType2 = RoomType::factory()->create(['branch_id' => $branch2->id]);
    $room2 = Room::factory()->create([
        'branch_id' => $branch2->id,
        'room_type_id' => $roomType2->id,
        'status' => 'occupied',
    ]);

    Reservation::factory()->checkedIn()->create([
        'branch_id' => $this->branch->id,
        'room_id' => $room1->id,
        'room_type_id' => $this->roomType->id,
        'check_in_date' => $yesterday,
        'check_out_date' => now()->addDays(3)->toDateString(),
    ]);
    Reservation::factory()->checkedIn()->create([
        'branch_id' => $branch2->id,
        'room_id' => $room2->id,
        'room_type_id' => $roomType2->id,
        'check_in_date' => $yesterday,
        'check_out_date' => now()->addDays(3)->toDateString(),
    ]);

    Artisan::call('night-audit', ['--date' => $yesterday]);

    $this->assertDatabaseHas('daily_ledgers', [
        'branch_id' => $this->branch->id,
        'status' => 'completed',
    ]);
    $this->assertDatabaseHas('daily_ledgers', [
        'branch_id' => $branch2->id,
        'status' => 'completed',
    ]);
});

it('applies correct tax rate to room charges', function () {
    $room = Room::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'status' => 'occupied',
    ]);

    $yesterday = now()->subDay()->toDateString();

    Reservation::factory()->checkedIn()->create([
        'branch_id' => $this->branch->id,
        'room_id' => $room->id,
        'room_type_id' => $this->roomType->id,
        'room_rate' => 10000,
        'check_in_date' => $yesterday,
        'check_out_date' => now()->addDays(3)->toDateString(),
    ]);

    Artisan::call('night-audit', ['--branch' => $this->branch->id, '--date' => $yesterday]);

    $ledger = DailyLedger::where('branch_id', $this->branch->id)->first();
    expect($ledger->total_room_revenue)->toBe(10000)
        ->and($ledger->total_tax)->toBeGreaterThan(0);
});

it('skips reservations not checked in on the business date', function () {
    $yesterday = now()->subDay()->toDateString();

    Reservation::factory()->confirmed()->create([
        'branch_id' => $this->branch->id,
        'room_id' => null,
        'room_type_id' => $this->roomType->id,
        'check_in_date' => now()->addDays(5)->toDateString(),
        'check_out_date' => now()->addDays(8)->toDateString(),
    ]);

    Artisan::call('night-audit', ['--branch' => $this->branch->id, '--date' => $yesterday]);

    $ledger = DailyLedger::where('branch_id', $this->branch->id)->first();
    expect($ledger->rooms_posted)->toBe(0);
});

it('calculates tax correctly with basis point conversion', function () {
    $room = Room::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'status' => 'occupied',
    ]);

    $yesterday = now()->subDay()->toDateString();

    Reservation::factory()->checkedIn()->create([
        'branch_id' => $this->branch->id,
        'room_id' => $room->id,
        'room_type_id' => $this->roomType->id,
        'room_rate' => 20000,
        'check_in_date' => $yesterday,
        'check_out_date' => now()->addDays(3)->toDateString(),
    ]);

    Artisan::call('night-audit', ['--branch' => $this->branch->id, '--date' => $yesterday]);

    $ledger = DailyLedger::where('branch_id', $this->branch->id)->first();
    $expectedTax = (int) round(20000 * 750 / 10000);

    expect($ledger->total_room_revenue)->toBe(20000)
        ->and($ledger->total_tax)->toBe($expectedTax);
});

it('posts multiple room charges and sums revenue correctly', function () {
    $room1 = Room::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'status' => 'occupied',
    ]);
    $room2 = Room::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'status' => 'occupied',
    ]);

    $yesterday = now()->subDay()->toDateString();

    Reservation::factory()->checkedIn()->create([
        'branch_id' => $this->branch->id,
        'room_id' => $room1->id,
        'room_type_id' => $this->roomType->id,
        'room_rate' => 10000,
        'check_in_date' => $yesterday,
        'check_out_date' => now()->addDays(3)->toDateString(),
    ]);
    Reservation::factory()->checkedIn()->create([
        'branch_id' => $this->branch->id,
        'room_id' => $room2->id,
        'room_type_id' => $this->roomType->id,
        'room_rate' => 15000,
        'check_in_date' => $yesterday,
        'check_out_date' => now()->addDays(3)->toDateString(),
    ]);

    Artisan::call('night-audit', ['--branch' => $this->branch->id, '--date' => $yesterday]);

    $ledger = DailyLedger::where('branch_id', $this->branch->id)->first();
    expect($ledger->rooms_posted)->toBe(2)
        ->and($ledger->total_room_revenue)->toBe(25000)
        ->and($ledger->total_tax)->toBeGreaterThan(0);
});

it('calculates net revenue as revenue plus tax plus other charges minus payments', function () {
    $room = Room::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'status' => 'occupied',
    ]);

    $yesterday = now()->subDay()->toDateString();

    $reservation = Reservation::factory()->checkedIn()->create([
        'branch_id' => $this->branch->id,
        'room_id' => $room->id,
        'room_type_id' => $this->roomType->id,
        'room_rate' => 10000,
        'check_in_date' => $yesterday,
        'check_out_date' => now()->addDays(3)->toDateString(),
    ]);

    $folio = Folio::create([
        'branch_id' => $this->branch->id,
        'reservation_id' => $reservation->id,
        'folio_number' => Folio::generateFolioNumber(),
        'type' => 'individual',
        'status' => 'open',
    ]);

    DB::table('pos_charges')->insert([
        'branch_id' => $this->branch->id,
        'reservation_id' => $reservation->id,
        'folio_id' => $folio->id,
        'outlet' => 'restaurant',
        'items' => json_encode([['name' => 'Dinner', 'qty' => 1, 'unit_price' => 5000, 'total' => 5000]]),
        'subtotal' => 5000,
        'tax_amount' => 375,
        'total' => 5375,
        'status' => 'posted',
        'created_at' => $yesterday,
        'updated_at' => $yesterday,
    ]);

    DB::table('payment_transactions')->insert([
        'branch_id' => $this->branch->id,
        'folio_id' => $folio->id,
        'reservation_id' => $reservation->id,
        'paystack_reference' => 'PAY-TEST001',
        'type' => 'charge',
        'status' => 'success',
        'amount' => 5000,
        'currency' => 'NGN',
        'customer_email' => 'test@example.com',
        'paid_at' => $yesterday,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Artisan::call('night-audit', ['--branch' => $this->branch->id, '--date' => $yesterday]);

    $ledger = DailyLedger::where('branch_id', $this->branch->id)->first();

    $expectedRoomRevenue = 10000;
    $expectedTax = (int) round(10000 * 750 / 10000);

    expect($ledger->total_room_revenue)->toBe($expectedRoomRevenue)
        ->and($ledger->total_tax)->toBe($expectedTax)
        ->and($ledger->net_revenue)->toBe(
            $expectedRoomRevenue + $expectedTax + $ledger->total_other_charges - $ledger->total_payments
        );
});

it('handles zero tax rate branch correctly', function () {
    $this->branch->update(['tax_rate' => 0]);

    $room = Room::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'status' => 'occupied',
    ]);

    $yesterday = now()->subDay()->toDateString();

    Reservation::factory()->checkedIn()->create([
        'branch_id' => $this->branch->id,
        'room_id' => $room->id,
        'room_type_id' => $this->roomType->id,
        'room_rate' => 10000,
        'check_in_date' => $yesterday,
        'check_out_date' => now()->addDays(3)->toDateString(),
    ]);

    Artisan::call('night-audit', ['--branch' => $this->branch->id, '--date' => $yesterday]);

    $ledger = DailyLedger::where('branch_id', $this->branch->id)->first();
    expect($ledger->total_room_revenue)->toBe(10000)
        ->and($ledger->total_tax)->toBe(0)
        ->and($ledger->net_revenue)->toBe(10000);
});
