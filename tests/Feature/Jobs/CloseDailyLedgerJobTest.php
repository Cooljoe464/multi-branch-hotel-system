<?php

use App\Jobs\CloseDailyLedgerJob;
use App\Models\Branch;
use App\Models\DailyLedger;
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

it('closes daily ledger and marks completed', function () {
    $job = new CloseDailyLedgerJob($this->branch->id, now()->toDateString());
    $ledger = $job->handle(new NightAuditService);

    expect($ledger->fresh()->status)->toBe('completed')
        ->and($ledger->fresh()->completed_at)->not->toBeNull();
});

it('creates ledger if none exists', function () {
    $this->assertDatabaseMissing(DailyLedger::class, [
        'branch_id' => $this->branch->id,
        'business_date' => now()->toDateString(),
    ]);

    $job = new CloseDailyLedgerJob($this->branch->id, now()->toDateString());
    $job->handle(new NightAuditService);

    $this->assertDatabaseHas(DailyLedger::class, [
        'branch_id' => $this->branch->id,
        'business_date' => now()->toDateString(),
        'status' => 'completed',
    ]);
});

it('is idempotent when called twice', function () {
    $job = new CloseDailyLedgerJob($this->branch->id, now()->toDateString());
    $first = $job->handle(new NightAuditService);
    $second = $job->handle(new NightAuditService);

    expect($first->id)->toBe($second->id)
        ->and($second->fresh()->status)->toBe('completed');
});

it('returns existing in_progress ledger', function () {
    $ledger = DailyLedger::create([
        'branch_id' => $this->branch->id,
        'business_date' => now()->toDateString(),
        'status' => 'in_progress',
        'started_at' => now(),
    ]);

    $job = new CloseDailyLedgerJob($this->branch->id, now()->toDateString());
    $result = $job->handle(new NightAuditService);

    expect($result->id)->toBe($ledger->id);
});

it('calculates net revenue with room charges', function () {
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

    $job = new CloseDailyLedgerJob($this->branch->id, now()->toDateString());
    $ledger = $job->handle(new NightAuditService);

    // room_revenue = 10000, tax = 1000, other = 0, payments = 0
    // net_revenue = 10000 + 1000 + 0 - 0 = 11000
    expect($ledger->fresh()->total_room_revenue)->toBe(10000)
        ->and($ledger->fresh()->total_tax)->toBe(1000)
        ->and($ledger->fresh()->net_revenue)->toBe(11000);
});

it('uses night-audit queue', function () {
    $job = new CloseDailyLedgerJob($this->branch->id, now()->toDateString());

    expect($job->queue)->toBe('night-audit');
});

it('has correct timeout and tries', function () {
    $job = new CloseDailyLedgerJob($this->branch->id, now()->toDateString());

    expect($job->tries)->toBe(3)
        ->and($job->timeout)->toBe(180);
});
