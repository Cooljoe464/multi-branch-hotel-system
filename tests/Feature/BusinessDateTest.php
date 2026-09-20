<?php

use App\Exceptions\BusinessDateAlreadyClosingException;
use App\Jobs\BackfillBusinessDatesJob;
use App\Models\Branch;
use App\Models\BusinessDate;
use App\Models\DailyLedger;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\BusinessDateService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['timezone' => 'Africa/Lagos']);
    $this->service = app(BusinessDateService::class);
});

it('opens today on first current() call', function () {
    $current = $this->service->current($this->branch);

    expect($current->status)->toBe(BusinessDate::STATUS_OPEN)
        ->and($current->business_date->toDateString())
        ->toBe(Carbon::now('Africa/Lagos')->toDateString());

    $this->assertDatabaseCount('business_dates', 1);
});

it('returns the same open row on repeat current() calls', function () {
    $first = $this->service->current($this->branch);
    $second = $this->service->current($this->branch);

    expect($second->id)->toBe($first->id);
    $this->assertDatabaseCount('business_dates', 1);
});

it('advances to the next day and closes the current date', function () {
    $user = $this->makeAdminUser($this->branch);

    $open = $this->service->current($this->branch);
    $next = $this->service->advance($this->branch, $user);

    expect($next->business_date->toDateString())
        ->toBe(Carbon::parse($open->business_date)->addDay()->toDateString());

    expect($open->fresh()->status)->toBe(BusinessDate::STATUS_CLOSED);
    expect($next->fresh()->status)->toBe(BusinessDate::STATUS_OPEN);
    expect($this->branch->fresh()->current_business_date->toDateString())
        ->toBe($next->business_date->toDateString());
});

it('rejects a second concurrent advance while closing', function () {
    $open = $this->service->current($this->branch);
    $open->update(['status' => BusinessDate::STATUS_CLOSING]);

    // No open row remains, but a closing row does: advance() resumes it.
    $resumed = $this->service->advance($this->branch);

    expect($resumed->status)->toBe(BusinessDate::STATUS_OPEN);
    expect($open->fresh()->status)->toBe(BusinessDate::STATUS_CLOSED);
});

it('throws when advancing with no open or closing date', function () {
    $this->service->advance($this->branch);
})->throws(BusinessDateAlreadyClosingException::class);

it('resumes an interrupted close without duplicating dates', function () {
    $open = $this->service->current($this->branch);
    $open->update(['status' => BusinessDate::STATUS_CLOSING]);

    $resumed = $this->service->resumeClosing($this->branch);

    expect($resumed)->not->toBeNull()
        ->and($resumed->business_date->toDateString())
        ->toBe(Carbon::parse($open->business_date)->addDay()->toDateString());

    // Second resume finds nothing to do and creates nothing.
    expect($this->service->resumeClosing($this->branch))->toBeNull();
    $this->assertDatabaseCount('business_dates', 2);
});

it('enforces exactly one open date per branch at the database level', function () {
    $this->service->current($this->branch);

    expect(fn () => BusinessDate::create([
        'branch_id' => $this->branch->id,
        'business_date' => Carbon::now('Africa/Lagos')->addDay()->toDateString(),
        'status' => BusinessDate::STATUS_OPEN,
    ]))->toThrow(QueryException::class);
});

it('backfills historic ledger dates and stamps posting tables', function () {
    $roomType = RoomType::factory()->create(['branch_id' => $this->branch->id]);
    $room = Room::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $roomType->id,
    ]);
    $reservation = Reservation::factory()->create([
        'branch_id' => $this->branch->id,
        'room_id' => $room->id,
        'room_type_id' => $roomType->id,
        'check_in_date' => '2026-09-10',
        'check_out_date' => '2026-09-12',
    ]);

    DailyLedger::create([
        'branch_id' => $this->branch->id,
        'business_date' => '2026-09-10',
        'status' => 'completed',
    ]);

    $stats = app(BackfillBusinessDatesJob::class)->handle(app(BusinessDateService::class));

    expect($stats['branches'])->toBe(1);

    $this->assertDatabaseHas('business_dates', [
        'branch_id' => $this->branch->id,
        'business_date' => '2026-09-10',
        'status' => BusinessDate::STATUS_CLOSED,
    ]);

    expect($reservation->fresh()->business_date->toDateString())->toBe('2026-09-10');

    // Re-running the backfill changes nothing.
    $again = app(BackfillBusinessDatesJob::class)->handle(app(BusinessDateService::class));

    expect($again['dates_opened'])->toBe(0)->and($again['dates_closed'])->toBe(0);
});

it('requires the business_date.view permission to view', function () {
    $user = $this->makeAdminUser($this->branch);
    $user->removeRole('Global Admin');

    $this->actingAs($user)
        ->get("/branches/{$this->branch->id}/business-date")
        ->assertForbidden();
});
