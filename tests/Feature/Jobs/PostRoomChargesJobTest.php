<?php

use App\Jobs\PostRoomChargesJob;
use App\Models\Branch;
use App\Models\Folio;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\NightAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['tax_rate' => 10.0]);
    $this->roomType = RoomType::factory()->create([
        'branch_id' => $this->branch->id,
        'base_rate' => 10000,
    ]);
});

it('posts room charges for checked-in reservations', function () {
    $room = Room::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
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
        'folio_number' => 'FOL-JOB-001',
        'type' => 'individual',
        'status' => 'open',
        'balance' => 0,
    ]);

    $job = new PostRoomChargesJob($this->branch->id, now()->toDateString());
    $result = $job->handle(new NightAuditService);

    expect($result['posted'])->toBe(1)
        ->and($result['total_room_revenue'])->toBe(15000)
        ->and($result['errors'])->toBeEmpty();
});

it('creates folio if none exists', function () {
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

    $job = new PostRoomChargesJob($this->branch->id, now()->toDateString());
    $result = $job->handle(new NightAuditService);

    expect($result['posted'])->toBe(1);

    $this->assertDatabaseHas(Folio::class, [
        'branch_id' => $this->branch->id,
        'type' => 'individual',
        'status' => 'open',
    ]);
});

it('returns zero when no checked-in reservations', function () {
    $job = new PostRoomChargesJob($this->branch->id, now()->toDateString());
    $result = $job->handle(new NightAuditService);

    expect($result['posted'])->toBe(0)
        ->and($result['total_room_revenue'])->toBe(0)
        ->and($result['total_tax'])->toBe(0);
});

it('calculates tax correctly', function () {
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

    $job = new PostRoomChargesJob($this->branch->id, now()->toDateString());
    $result = $job->handle(new NightAuditService);

    // tax_rate = 10.0, taxRateBps = 1000
    // taxAmount = round(10000 * 1000 / 10000) = 1000
    expect($result['total_tax'])->toBe(1000);
});

it('uses night-audit queue', function () {
    $job = new PostRoomChargesJob($this->branch->id, now()->toDateString());

    expect($job->queue)->toBe('night-audit');
});

it('has correct timeout and tries', function () {
    $job = new PostRoomChargesJob($this->branch->id, now()->toDateString());

    expect($job->tries)->toBe(3)
        ->and($job->timeout)->toBe(120);
});
