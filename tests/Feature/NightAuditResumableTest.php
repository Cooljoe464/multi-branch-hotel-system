<?php

use App\Models\Branch;
use App\Models\BusinessDate;
use App\Models\Folio;
use App\Models\JournalEntry;
use App\Models\NightAuditRun;
use App\Models\Reservation;
use App\Models\ReservationNight;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Transaction;
use App\Services\AvailabilityService;
use App\Services\BusinessDateService;
use App\Services\FolioService as FolioSvc;
use App\Services\NightAuditService;
use Carbon\Carbon;
use Database\Seeders\ChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['tax_rate' => 7.5, 'timezone' => 'Africa/Lagos']);
    $this->user = $this->makeAdminUser($this->branch);
    $this->seed(ChartSeeder::class);
    // All dates in this file derive from the branch business date, never
    // the server clock: near midnight UTC the two differ by a day.
    $this->today = app(BusinessDateService::class)->current($this->branch)->business_date->toDateString();
    $this->roomType = RoomType::factory()->create(['branch_id' => $this->branch->id]);
    $this->room = Room::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'status' => 'occupied',
    ]);
    $this->audit = (new NightAuditService)->forBranch($this->branch);
});

function stayAttributes(string $guest): array
{
    return [
        'guest_name' => $guest,
        'adults' => 2,
        'children' => 0,
        'room_rate' => 20000,
        'total_amount' => 40000,
        'status' => 'confirmed',
        'source' => 'direct',
        'payment_status' => 'pending',
    ];
}

it('runs the full audit and advances the business date', function () {
    $date = $this->today;
    $yesterday = Carbon::parse($date)->subDay()->toDateString();
    $plus2 = Carbon::parse($date)->addDays(2)->toDateString();

    Reservation::factory()->checkedIn()->create([
        'branch_id' => $this->branch->id,
        'room_id' => $this->room->id,
        'room_type_id' => $this->roomType->id,
        'room_rate' => 20000,
        'check_in_date' => $yesterday,
        'check_out_date' => $plus2,
    ]);

    $run = $this->audit->run($date, null, $this->user);

    expect($run->status)->toBe(NightAuditRun::STATUS_RECONCILED);
    expect($run->steps['post_room']['posted'] ?? 0)->toBe(1);
    expect($run->steps['trial_balance']['balanced'] ?? false)->toBeTrue();

    expect($this->branch->fresh()->current_business_date->toDateString())->not->toBe($date);

    $this->assertDatabaseHas('daily_ledgers', [
        'branch_id' => $this->branch->id,
        'business_date' => $date,
        'status' => 'completed',
    ]);

    $again = $this->audit->run($date, null, $this->user);
    expect($again->id)->toBe($run->id);
    expect(Transaction::where('category', 'room_rate')->where('is_voided', false)->count())->toBe(1);
});

it('resumes after a crash without double-posting', function () {
    $date = $this->today;
    $yesterday = Carbon::parse($date)->subDay()->toDateString();
    $plus2 = Carbon::parse($date)->addDays(2)->toDateString();

    $rooms = [$this->room];
    for ($i = 0; $i < 4; $i++) {
        $rooms[] = Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'status' => 'occupied',
        ]);
    }

    foreach ($rooms as $index => $room) {
        Reservation::factory()->checkedIn()->create([
            'branch_id' => $this->branch->id,
            'room_id' => $room->id,
            'room_type_id' => $this->roomType->id,
            'room_rate' => 20000,
            'guest_name' => "Crash Guest {$index}",
            'check_in_date' => $yesterday,
            'check_out_date' => $plus2,
        ]);
    }

    // Simulate a crash: the first two rooms already charged for the date.
    $firstTwo = Reservation::forBranch($this->branch->id)->orderBy('id')->limit(2)->get();
    foreach ($firstTwo as $reservation) {
        $folio = Folio::where('reservation_id', $reservation->id)->first()
            ?? (new FolioSvc)->createFolio($this->branch->id, $reservation->id);
        (new FolioSvc)->postDebit($folio, 'room_rate', "Room charge: X - {$date}", $reservation->room_rate);
    }

    $run = $this->audit->run($date, null, $this->user);

    expect($run->status)->toBe(NightAuditRun::STATUS_RECONCILED);
    expect($run->steps['post_room']['posted'] ?? -1)->toBe(3);
    expect(Transaction::where('category', 'room_rate')->where('is_voided', false)->count())->toBe(5);
});

it('charges no-show fees and releases inventory', function () {
    $date = Carbon::parse($this->today)->addDay()->toDateString();
    $out = Carbon::parse($this->today)->addDays(3)->toDateString();

    $reservation = app(AvailabilityService::class)->reserve(
        $this->branch, $this->roomType, $date, $out,
        stayAttributes('No-show Guest'), $this->room->id, (string) Str::uuid(),
    );

    $run = $this->audit->run($date, null, $this->user);

    expect($run->status)->toBe(NightAuditRun::STATUS_RECONCILED);
    expect($run->steps['no_show']['processed'] ?? 0)->toBe(1);

    $reservation->refresh();
    expect($reservation->status)->toBe('no_show')
        ->and($reservation->audit_outcome)->toBe('no_show')
        ->and($reservation->no_show_fee_minor)->toBe(20000);

    expect(ReservationNight::forReservation($reservation->id)->count())->toBe(0);

    $this->assertDatabaseHas('transactions', [
        'category' => 'no_show_fee',
        'amount' => 20000,
    ]);
});

it('posts a day-use charge for same-day stays', function () {
    $date = $this->today;

    Reservation::factory()->checkedIn()->create([
        'branch_id' => $this->branch->id,
        'room_id' => $this->room->id,
        'room_type_id' => $this->roomType->id,
        'room_rate' => 20000,
        'check_in_date' => $date,
        'check_out_date' => $date,
    ]);

    $run = $this->audit->run($date, null, $this->user);

    expect($run->status)->toBe(NightAuditRun::STATUS_RECONCILED);
    expect($run->steps['day_use']['processed'] ?? 0)->toBe(1);
    expect($run->steps['day_use']['revenue_minor'] ?? 0)->toBe(10000);

    $this->assertDatabaseHas('transactions', [
        'category' => 'day_use',
        'amount' => 10000,
    ]);
});

it('releases future nights on early departure', function () {
    $date = $this->today;

    $reservation = app(AvailabilityService::class)->reserve(
        $this->branch, $this->roomType,
        Carbon::parse($date)->subDays(2)->toDateString(),
        Carbon::parse($date)->addDays(2)->toDateString(),
        array_merge(stayAttributes('Early Guest'), ['status' => 'checked_in']),
        $this->room->id, (string) Str::uuid(),
    );
    $reservation->update(['actual_check_out_at' => Carbon::parse($date)->subDay()->setTime(11, 0)]);

    $run = $this->audit->run($date, null, $this->user);

    expect($run->status)->toBe(NightAuditRun::STATUS_RECONCILED);
    expect($run->steps['early_departure']['processed'] ?? 0)->toBe(1);
    expect($reservation->fresh()->audit_outcome)->toBe('early_departure');
});

it('blocks advance on an unbalanced day', function () {
    $date = $this->today;

    JournalEntry::create([
        'branch_id' => $this->branch->id,
        'business_date' => $date,
        'event' => 'charge.posted',
        'debit_account' => 'BOGUS',
        'credit_account' => 'REVENUE',
        'amount_minor' => 1000,
        'currency_code' => 'NGN',
        'posted_at' => now(),
    ]);

    $run = $this->audit->run($date, null, $this->user);

    expect($run->status)->toBe(NightAuditRun::STATUS_FAILED);

    // Business date did not advance past the broken day.
    $open = BusinessDate::forBranch($this->branch->id)->open()->firstOrFail();
    expect($open->business_date->toDateString())->toBe($date);
});

it('serves the night audit pages under audit permissions', function () {
    $this->withoutVite();

    $this->actingAs($this->user)->get("/branches/{$this->branch->id}/night-audit")->assertOk();

    $user = $this->makeAdminUser($this->branch);
    $user->removeRole('Global Admin');

    $this->actingAs($user)->get("/branches/{$this->branch->id}/night-audit")->assertForbidden();
});
