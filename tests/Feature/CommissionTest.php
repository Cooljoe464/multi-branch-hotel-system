<?php

use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Models\CommissionAccrual;
use App\Models\CommissionRule;
use App\Models\JournalEntry;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\CommissionService;
use Database\Seeders\ChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['timezone' => 'Africa/Lagos']);
    $this->user = $this->makeAdminUser($this->branch);
    $this->seed(ChartSeeder::class);
    $this->roomType = RoomType::factory()->create(['branch_id' => $this->branch->id, 'base_rate' => 50000]);
    $this->room = Room::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'status' => 'available',
    ]);
    $this->rule = CommissionRule::create([
        'branch_id' => $this->branch->id,
        'source' => 'bookingcom',
        'rate_bps' => 1500,
        'base' => 'net_room',
        'active' => true,
    ]);
    $this->service = app(CommissionService::class);
});

function otaStay(int $branchId, int $roomTypeId, int $nightly, int $nights = 2, string $source = 'bookingcom'): Reservation
{
    $components = [];
    $snapshotNights = [];
    for ($i = 1; $i <= $nights; $i++) {
        $date = '2026-10-'.str_pad((string) (10 + $i), 2, '0', STR_PAD_LEFT);
        $components = [
            ['code' => 'room', 'label' => 'Room', 'amount_minor' => $nightly, 'category' => 'room_rate'],
        ];
        $snapshotNights[] = ['date' => $date, 'total_minor' => $nightly, 'components' => $components];
    }

    return Reservation::factory()->checkedOut()->create([
        'branch_id' => $branchId,
        'room_type_id' => $roomTypeId,
        'source' => $source,
        'room_rate' => $nightly,
        'total_amount' => $nightly * $nights,
        'rate_snapshot' => ['total_minor' => $nightly * $nights, 'nights' => $snapshotNights],
        'check_in_date' => '2026-10-11',
        'check_out_date' => '2026-10-'.(11 + $nights),
    ]);
}

it('accrues 15% on net room once and never restates', function () {
    $stay = otaStay($this->branch->id, $this->roomType->id, 50000);

    // Net room 100000 × 1500bps = 15000.
    $accrual = $this->service->accrue($stay, $this->user);

    expect($accrual->amount_minor)->toBe(15000)
        ->and($accrual->base_minor)->toBe(100000)
        ->and($accrual->status)->toBe(CommissionAccrual::STATUS_ACCRUED);

    // Re-run returns the original row with a single journal line.
    $again = $this->service->accrue($stay, $this->user);

    expect($again->id)->toBe($accrual->id);
    expect(JournalEntry::where('event', 'commission.accrued')->count())->toBe(1);

    // Rule edits do not restate frozen history.
    $this->rule->update(['rate_bps' => 2000]);
    $frozen = $this->service->accrue($stay, $this->user);

    expect($frozen->amount_minor)->toBe(15000);
});

it('skips direct bookings and unknown sources', function () {
    $direct = otaStay($this->branch->id, $this->roomType->id, 50000, 2, 'direct');
    $unknown = otaStay($this->branch->id, $this->roomType->id, 50000, 2, 'walk_in');

    expect($this->service->accrue($direct))->toBeNull();
    expect($this->service->accrue($unknown))->toBeNull();
    expect(CommissionAccrual::count())->toBe(0);
});

it('builds partial payouts and rejects mismatched amounts', function () {
    $first = otaStay($this->branch->id, $this->roomType->id, 50000);
    $second = otaStay($this->branch->id, $this->roomType->id, 50000);

    $firstAccrual = $this->service->accrue($first);
    $secondAccrual = $this->service->accrue($second);

    // Partial payout over one line.
    $payout = $this->service->createPayout($this->branch, 'bookingcom', [$secondAccrual->id], null, 'WIRE-1', $this->user);

    expect($payout->amount_minor)->toBe(15000)
        ->and($secondAccrual->fresh()->status)->toBe(CommissionAccrual::STATUS_INVOICED);

    // Over-payout is refused.
    expect(fn () => $this->service->createPayout($this->branch, 'bookingcom', [$firstAccrual->id], 99999, null, $this->user))
        ->toThrow(AvailabilityException::class, 'does not match');
});

it('excludes disputed accruals from payouts until resolved', function () {
    $stay = otaStay($this->branch->id, $this->roomType->id, 50000);
    $accrual = $this->service->accrue($stay);

    $this->service->dispute($accrual);

    expect($accrual->fresh()->status)->toBe(CommissionAccrual::STATUS_DISPUTED);
    expect(fn () => $this->service->createPayout($this->branch, 'bookingcom', [$accrual->id], null, null, $this->user))
        ->toThrow(AvailabilityException::class, 'disputed');

    $this->service->resolveDispute($accrual->fresh());

    $payout = $this->service->createPayout($this->branch, 'bookingcom', [$accrual->id], null, null, $this->user);

    expect($payout->amount_minor)->toBe(15000);
});

it('pays payouts, clears the payable and marks lines paid', function () {
    $stay = otaStay($this->branch->id, $this->roomType->id, 50000);
    $accrual = $this->service->accrue($stay);

    $payout = $this->service->createPayout($this->branch, 'bookingcom', [$accrual->id], null, 'WIRE-2', $this->user);
    $paid = $this->service->payPayout($payout, $this->user);

    expect($paid->status)->toBe('paid')
        ->and($accrual->fresh()->status)->toBe(CommissionAccrual::STATUS_PAID);

    $this->assertDatabaseHas('journal_entries', [
        'event' => 'commission.paid',
        'amount_minor' => 15000,
    ]);

    expect(fn () => $this->service->payPayout($paid, $this->user))
        ->toThrow(AvailabilityException::class, 'Only pending');
});
