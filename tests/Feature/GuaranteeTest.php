<?php

use App\Jobs\ReleaseHoldsJob;
use App\Models\Branch;
use App\Models\GuaranteePolicy;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Transaction;
use App\Services\AvailabilityService;
use App\Services\BusinessDateService;
use App\Services\GuaranteeService;
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
    $this->today = app(BusinessDateService::class)->current($this->branch)->business_date->toDateString();
    $this->roomType = RoomType::factory()->create(['branch_id' => $this->branch->id, 'base_rate' => 10000]);
    $this->room = Room::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'status' => 'available',
    ]);
    $this->service = app(GuaranteeService::class);
});

function guaranteePolicy(int $branchId, array $rules = [], ?int $planId = null): GuaranteePolicy
{
    return GuaranteePolicy::create([
        'branch_id' => $branchId,
        'rate_plan_id' => $planId,
        'kind' => 'deposit_schedule',
        'rules' => array_merge([
            'deposit_bps' => 2000,
            'due_hours_before_arrival' => 48,
            'hold_hours' => 24,
            'cancel_free_until_hours' => 24,
            'no_show_fee' => 'first_night',
            'no_show_fee_bps' => 0,
        ], $rules),
        'is_active' => true,
    ]);
}

function holdAttrs(string $guest, string $in, string $out): array
{
    return [
        'guest_name' => $guest,
        'adults' => 2,
        'children' => 0,
        'room_rate' => 10000,
        'total_amount' => 20000,
        'status' => 'confirmed',
        'source' => 'direct',
        'payment_status' => 'pending',
        'check_in_date' => $in,
        'check_out_date' => $out,
    ];
}

it('stamps deposit and deadlines on reserve', function () {
    guaranteePolicy($this->branch->id);

    $in = Carbon::parse($this->today)->addDays(5)->toDateString();
    $out = Carbon::parse($this->today)->addDays(7)->toDateString();

    $reservation = app(AvailabilityService::class)->reserve(
        $this->branch, $this->roomType, $in, $out,
        holdAttrs('Hold Guest', $in, $out),
        null, (string) Str::uuid(),
    );

    // 20% of 20000.
    expect($reservation->guarantee_status)->toBe('hold')
        ->and($reservation->deposit_due_minor)->toBe(4000)
        ->and($reservation->deposit_paid_minor)->toBe(0)
        ->and($reservation->cancel_deadline_at)->not->toBeNull()
        ->and($reservation->hold_expires_at)->not->toBeNull();
});

it('expires holds, frees inventory and ignores re-runs', function () {
    guaranteePolicy($this->branch->id, ['hold_hours' => 1]);

    $in = Carbon::parse($this->today)->addDays(5)->toDateString();
    $out = Carbon::parse($this->today)->addDays(7)->toDateString();

    $reservation = app(AvailabilityService::class)->reserve(
        $this->branch, $this->roomType, $in, $out,
        holdAttrs('Expiring Guest', $in, $out),
        null, (string) Str::uuid(),
    );

    expect(app(AvailabilityService::class)->sellableFor($this->branch, $this->roomType, $in))->toBe(0);

    $reservation->update(['hold_expires_at' => now()->subMinute()]);

    $result = (new ReleaseHoldsJob)->handle();

    expect($result['released'])->toBe(1);
    expect($reservation->fresh()->status)->toBe('cancelled');
    expect(app(AvailabilityService::class)->sellableFor($this->branch, $this->roomType, $in))->toBe(1);

    // Re-run is a no-op: the hold is already cancelled.
    expect((new ReleaseHoldsJob)->handle())->toBe(['released' => 0, 'overdue_flagged' => 0]);
});

it('posts the no-show penalty exactly once across double audit runs', function () {
    guaranteePolicy($this->branch->id, ['no_show_fee' => 'percent', 'no_show_fee_bps' => 5000]);

    $date = Carbon::parse($this->today)->addDay()->toDateString();
    $out = Carbon::parse($this->today)->addDays(3)->toDateString();

    $reservation = app(AvailabilityService::class)->reserve(
        $this->branch, $this->roomType, $date, $out,
        holdAttrs('No-show Guest', $date, $out),
        $this->room->id, (string) Str::uuid(),
    );

    // 50% of 20000.
    $run = (new NightAuditService)->forBranch($this->branch)->run($date, null, $this->user);

    expect($run->steps['no_show']['processed'] ?? 0)->toBe(1);
    expect($reservation->fresh()->no_show_fee_minor)->toBe(10000);
    expect($reservation->fresh()->guarantee_status)->toBe('forfeited');

    // A second audit over the same date posts nothing more.
    $fees = Transaction::where('category', 'no_show_fee')->where('is_voided', false)->sum('amount');

    expect((int) $fees)->toBe(10000);
});

it('cancels free before the deadline and charges after it', function () {
    guaranteePolicy($this->branch->id);

    $in = Carbon::parse($this->today)->addDays(5)->toDateString();
    $out = Carbon::parse($this->today)->addDays(7)->toDateString();

    $early = app(AvailabilityService::class)->reserve(
        $this->branch, $this->roomType, $in, $out,
        holdAttrs('Early Cancel', $in, $out),
        null, (string) Str::uuid(),
    );

    $outcome = $this->service->cancelReservation($early, $this->user);

    expect($outcome)->toBe(['fee_minor' => 0, 'waived' => false]);
    expect($early->fresh()->status)->toBe('cancelled');
    expect(Transaction::where('category', 'cancellation_fee')->count())->toBe(0);

    $late = app(AvailabilityService::class)->reserve(
        $this->branch, $this->roomType, $in, $out,
        holdAttrs('Late Cancel', $in, $out),
        null, (string) Str::uuid(),
    );
    // Deadline has passed: first-night fee applies.
    $late->update(['cancel_deadline_at' => now()->subHour()]);

    $outcome = $this->service->cancelReservation($late, $this->user);

    expect($outcome['fee_minor'])->toBe(10000);
    expect($late->fresh()->guarantee_status)->toBe('forfeited');

    $this->assertDatabaseHas('transactions', [
        'category' => 'cancellation_fee',
        'amount' => 10000,
    ]);
});

it('collects deposits and guarantees the hold', function () {
    guaranteePolicy($this->branch->id);

    $in = Carbon::parse($this->today)->addDays(5)->toDateString();
    $out = Carbon::parse($this->today)->addDays(7)->toDateString();

    $reservation = app(AvailabilityService::class)->reserve(
        $this->branch, $this->roomType, $in, $out,
        holdAttrs('Deposit Guest', $in, $out),
        null, (string) Str::uuid(),
    );

    $this->service->collectDeposit($reservation, 4000, $this->user, 'card', 'DEP-1');

    $reservation->refresh();

    expect($reservation->deposit_paid_minor)->toBe(4000)
        ->and($reservation->guarantee_status)->toBe('guaranteed');

    $this->assertDatabaseHas('transactions', [
        'category' => 'deposit',
        'amount' => 4000,
    ]);
});

it('forbids penalty waivers without permission over HTTP', function () {
    guaranteePolicy($this->branch->id);

    $frontDesk = $this->makeAdminUser($this->branch);
    $frontDesk->removeRole('Global Admin');
    $frontDesk->assignRole('Front Desk');
    // Cancel is allowed; only the waiver must be refused.
    $frontDesk->givePermissionTo('reservations.cancel');

    $in = Carbon::parse($this->today)->addDays(5)->toDateString();
    $out = Carbon::parse($this->today)->addDays(7)->toDateString();

    $reservation = app(AvailabilityService::class)->reserve(
        $this->branch, $this->roomType, $in, $out,
        holdAttrs('Waiver Guest', $in, $out),
        null, (string) Str::uuid(),
    );
    $reservation->update(['cancel_deadline_at' => now()->subHour()]);

    $this->actingAs($frontDesk)
        ->post(route('reservations.cancel', $reservation), ['waive_penalty' => true])
        ->assertForbidden();

    expect($reservation->fresh()->status)->not->toBe('cancelled');
});
