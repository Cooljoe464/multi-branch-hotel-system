<?php

use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Models\CorporateAccount;
use App\Models\NightAuditRun;
use App\Models\PromoCode;
use App\Models\RatePlan;
use App\Models\RateSeason;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Transaction;
use App\Services\AvailabilityService;
use App\Services\BusinessDateService;
use App\Services\FolioService;
use App\Services\NightAuditService;
use App\Services\RateEngine;
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
    $this->bar = RatePlan::factory()->bar()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => null,
        'rate_multiplier' => 1.0,
    ]);
    $this->engine = app(RateEngine::class);
});

function engineAttrs(string $guest = 'Engine Guest'): array
{
    return [
        'guest_name' => $guest,
        'adults' => 2,
        'children' => 0,
        'room_rate' => 10000,
        'total_amount' => 10000,
        'status' => 'confirmed',
        'source' => 'direct',
        'payment_status' => 'pending',
    ];
}

it('prices seasons and derived plans in integer minor units', function () {
    RateSeason::create([
        'branch_id' => $this->branch->id,
        'name' => 'Detty December',
        'code' => 'DETTY',
        'start_month' => 12, 'start_day' => 20,
        'end_month' => 1, 'end_day' => 5,
        'multiplier_bps' => 12000,
        'priority' => 10,
        'is_active' => true,
    ]);

    $derived = RatePlan::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => null,
        'rate_multiplier' => 1.0,
        'base_plan_id' => $this->bar->id,
        'derivation_bps' => -1000,
        'derivation_fixed_minor' => -200,
    ]);

    // Base 10000 → seasonal 12000 → -10% = 10800 → -200 = 10600.
    $quote = $this->engine->price($this->branch, $derived, $this->roomType, '2026-12-22', '2026-12-23');

    expect($quote['total_minor'])->toBe(10600)
        ->and($quote['nights'][0]['base_minor'])->toBe(10000)
        ->and($quote['nights'][0]['season_code'])->toBe('DETTY')
        ->and($quote['nights'][0]['derived_minor'])->toBe(10600);

    // Outside the season the same plan prices off the plain base.
    $plain = $this->engine->price($this->branch, $derived, $this->roomType, '2026-11-10', '2026-11-11');

    expect($plain['total_minor'])->toBe(8800);
});

it('rejects self-derivation and missing base plans', function () {
    $self = RatePlan::factory()->create([
        'branch_id' => $this->branch->id,
        'rate_multiplier' => 1.0,
    ]);
    $self->update(['base_plan_id' => $self->id]);

    expect(fn () => $this->engine->price($this->branch, $self, $this->roomType, '2026-11-10', '2026-11-11'))
        ->toThrow(AvailabilityException::class, 'cannot derive from itself');

    $orphan = RatePlan::factory()->create([
        'branch_id' => $this->branch->id,
        'rate_multiplier' => 1.0,
        'base_plan_id' => $this->bar->id,
    ]);
    $this->bar->delete();

    expect(fn () => $this->engine->price($this->branch, $orphan, $this->roomType, '2026-11-10', '2026-11-11'))
        ->toThrow(AvailabilityException::class, 'no active base plan');
});

it('splits packages into components that sum exactly to the nightly total', function () {
    $package = RatePlan::factory()->package()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => null,
        'rate_multiplier' => 1.0,
        'package_components' => [
            ['code' => 'breakfast', 'label' => 'Breakfast for two', 'amount_minor' => 1500, 'category' => 'package_extra'],
        ],
    ]);

    $quote = $this->engine->price($this->branch, $package, $this->roomType, '2026-11-10', '2026-11-12');

    expect($quote['total_minor'])->toBe(20000);

    foreach ($quote['nights'] as $night) {
        $sum = array_sum(array_column($night['components'], 'amount_minor'));

        expect($sum)->toBe($night['total_minor'])
            ->and($night['components'][0]['category'])->toBe('room_rate')
            ->and($night['components'][0]['amount_minor'])->toBe(8500)
            ->and($night['components'][1]['amount_minor'])->toBe(1500);
    }
});

it('caps promo redemptions across sequential bookings', function () {
    Room::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'status' => 'available',
    ]);

    $promo = PromoCode::create([
        'branch_id' => $this->branch->id,
        'code' => 'FLASH10',
        'discount_bps' => 1000,
        'max_uses' => 1,
        'min_nights' => 1,
        'valid_from' => Carbon::parse($this->today)->subYear()->toDateString(),
        'valid_to' => Carbon::parse($this->today)->addYear()->toDateString(),
        'is_active' => true,
    ]);

    // 2 nights at 10000 with 10% off = 18000.
    $quote = $this->engine->price($this->branch, $this->bar, $this->roomType, '2026-11-10', '2026-11-12', $promo);

    expect($quote['total_minor'])->toBe(18000)
        ->and($quote['promo_discount_minor'])->toBe(2000);

    $first = app(AvailabilityService::class)->reserve(
        $this->branch, $this->roomType, '2026-11-10', '2026-11-12',
        array_merge(engineAttrs('Promo One'), ['room_rate' => 9000, 'total_amount' => 18000]),
        null, (string) Str::uuid(), null, null, $this->bar, null, null, $quote, $promo,
    );

    expect($first->promo_code_id)->toBe($promo->id)
        ->and($promo->fresh()->uses_count)->toBe(1);

    expect(fn () => app(AvailabilityService::class)->reserve(
        $this->branch, $this->roomType, '2026-11-10', '2026-11-12',
        engineAttrs('Promo Two'),
        null, (string) Str::uuid(), null, null, $this->bar, null, null, $quote, $promo->fresh(),
    ))->toThrow(AvailabilityException::class, 'redemption limit');
});

it('validates promo windows and minimum nights at quote time', function () {
    $expired = PromoCode::create([
        'branch_id' => $this->branch->id,
        'code' => 'OLD',
        'discount_bps' => 1000,
        'min_nights' => 1,
        'valid_from' => '2020-01-01',
        'valid_to' => '2020-12-31',
        'is_active' => true,
    ]);

    expect(fn () => $this->engine->price($this->branch, $this->bar, $this->roomType, '2026-11-10', '2026-11-11', $expired))
        ->toThrow(AvailabilityException::class, 'not valid');

    $longStay = PromoCode::create([
        'branch_id' => $this->branch->id,
        'code' => 'WEEK',
        'discount_bps' => 1000,
        'min_nights' => 7,
        'valid_from' => Carbon::parse($this->today)->subYear()->toDateString(),
        'is_active' => true,
    ]);

    expect(fn () => $this->engine->price($this->branch, $this->bar, $this->roomType, '2026-11-10', '2026-11-11', $longStay))
        ->toThrow(AvailabilityException::class, 'at least 7 nights');
});

it('prices corporate negotiated plans with the account discount', function () {
    $negotiated = RatePlan::factory()->corporate()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => null,
        'rate_multiplier' => 1.0,
    ]);

    $corporate = CorporateAccount::create([
        'branch_id' => $this->branch->id,
        'name' => 'Dangote Travel',
        'code' => 'DANG',
        'negotiated_plan_id' => $negotiated->id,
        'discount_bps' => 500,
        'is_active' => true,
    ]);

    // 10000 - 5% = 9500, priced on the negotiated plan.
    $quote = $this->engine->price($this->branch, $negotiated, $this->roomType, '2026-11-10', '2026-11-11', null, $corporate);

    expect($quote['total_minor'])->toBe(9500)
        ->and($quote['corporate_discount_minor'])->toBe(500)
        ->and($quote['corporate_code'])->toBe('DANG');
});

it('freezes the snapshot and posts package component lines at night audit', function () {
    $package = RatePlan::factory()->package()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => null,
        'rate_multiplier' => 1.0,
        'package_components' => [
            ['code' => 'breakfast', 'label' => 'Breakfast for two', 'amount_minor' => 1500, 'category' => 'package_extra'],
        ],
    ]);

    $date = $this->today;
    $out = Carbon::parse($date)->addDays(2)->toDateString();

    $quote = $this->engine->price($this->branch, $package, $this->roomType, $date, $out);

    $reservation = app(AvailabilityService::class)->reserve(
        $this->branch, $this->roomType, $date, $out,
        array_merge(engineAttrs('Package Guest'), [
            'room_rate' => $quote['nights'][0]['total_minor'],
            'total_amount' => $quote['total_minor'],
            'status' => 'checked_in',
        ]),
        $this->room->id, (string) Str::uuid(), null, null, $package, null, null, $quote, null,
    );

    // Rates move after booking; the frozen snapshot must not.
    $package->update(['rate_multiplier' => 2.0, 'package_components' => []]);

    $reservation->refresh();

    expect($reservation->rate_snapshot['total_minor'])->toBe(20000)
        ->and($reservation->rate_plan_id)->toBe($package->id);

    $run = (new NightAuditService)->forBranch($this->branch)->run($date, null, $this->user);

    expect($run->status)->toBe(NightAuditRun::STATUS_RECONCILED);

    $lines = Transaction::whereHas('folio', fn ($q) => $q->where('reservation_id', $reservation->id))
        ->where('is_voided', false)
        ->orderBy('id')
        ->get();

    expect($lines->pluck('amount')->all())->toBe([8500, 1500])
        ->and($lines->pluck('category')->all())->toBe(['room_rate', 'package_extra'])
        ->and($lines->sum('amount'))->toBe(10000);

    $bill = (new FolioService)->generateBillData($reservation->folio()->firstOrFail());

    expect($bill['rate_breakdown']['total_minor'])->toBe(20000);
});
