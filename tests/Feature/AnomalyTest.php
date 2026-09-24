<?php

use App\Models\AnomalyFinding;
use App\Models\AnomalyRule;
use App\Models\AuditFlag;
use App\Models\Branch;
use App\Models\Folio;
use App\Models\RateOverride;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Transaction;
use App\Services\AnomalyService;
use App\Services\AvailabilityService;
use App\Services\BusinessDateService;
use Database\Seeders\ChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['timezone' => 'Africa/Lagos']);
    $this->user = $this->makeAdminUser($this->branch);
    $this->seed(ChartSeeder::class);
    $this->today = app(BusinessDateService::class)->current($this->branch)->business_date->toDateString();
    $this->folio = Folio::factory()->create(['branch_id' => $this->branch->id]);
    $this->service = new AnomalyService;
});

function seedVoidDay(Folio $folio, string $day, int $total, int $voids): void
{
    if ($total - $voids > 0) {
        Transaction::factory()->count($total - $voids)->create([
            'folio_id' => $folio->id, 'business_date' => $day,
        ]);
    }

    if ($voids > 0) {
        Transaction::factory()->voided()->count($voids)->create([
            'folio_id' => $folio->id, 'business_date' => $day,
        ]);
    }
}

function seedBaseline(Folio $folio, string $today, int $perDay, int $voids): void
{
    for ($i = 1; $i <= 28; $i++) {
        seedVoidDay($folio, Carbon::parse($today)->subDays($i)->toDateString(), $perDay, $voids);
    }
}

it('raises a void burst once and never duplicates on re-scan', function () {
    seedBaseline($this->folio, $this->today, 20, 1);
    seedVoidDay($this->folio, $this->today, 20, 12);

    $first = $this->service->scanBranch($this->branch, $this->today);
    $codes = collect($first)->pluck('rule_code')->all();

    expect($codes)->toContain('void_rate');

    $finding = collect($first)->firstWhere('rule_code', 'void_rate');

    expect($finding->score)->toBeGreaterThan(AnomalyService::FLAG_GATE)
        ->and(AnomalyFinding::forBranch($this->branch->id)->open()->count())->toBe(1);

    $second = $this->service->scanBranch($this->branch, $this->today);

    expect($second)->toBeEmpty()
        ->and(AnomalyFinding::forBranch($this->branch->id)->open()->count())->toBe(1);
});

it('stays quiet on a benign high-volume conference day', function () {
    seedBaseline($this->folio, $this->today, 20, 1);
    seedVoidDay($this->folio, $this->today, 200, 10);

    $findings = $this->service->scanBranch($this->branch, $this->today);

    expect(collect($findings)->pluck('rule_code')->all())->not->toContain('void_rate');
});

it('honours tuned sensitivity on future scans', function () {
    // Baseline alternates 0/1 voids per 20: mean .025, std .025.
    for ($i = 1; $i <= 28; $i++) {
        seedVoidDay($this->folio, Carbon::parse($this->today)->subDays($i)->toDateString(), 20, $i % 2);
    }
    seedVoidDay($this->folio, $this->today, 20, 3);

    $flagged = $this->service->scanBranch($this->branch, $this->today);

    expect(collect($flagged)->pluck('rule_code')->all())->toContain('void_rate');

    AnomalyFinding::query()->delete();
    AuditFlag::where('flag_type', 'anomaly')->delete();

    $rule = AnomalyRule::whereNull('branch_id')->where('code', 'void_rate')->firstOrFail();
    $rule->tune(['sensitivity' => 0.5]);

    $quiet = $this->service->scanBranch($this->branch, $this->today);

    expect(collect($quiet)->pluck('rule_code')->all())->not->toContain('void_rate');
});

it('flags after-hours rate changes in branch time', function () {
    $roomType = RoomType::factory()->create(['branch_id' => $this->branch->id, 'base_rate' => 10000]);

    // 03:10 Lagos time.
    $at = Carbon::parse($this->today.' 03:10:00', 'Africa/Lagos');

    RateOverride::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $roomType->id,
        'created_at' => $at,
        'updated_at' => $at,
    ]);

    $findings = $this->service->scanBranch($this->branch, $this->today);
    $codes = collect($findings)->pluck('rule_code')->all();

    expect($codes)->toContain('rate_change_after_hours');
});

it('flags no-show stays missing their fee', function () {
    $roomType = RoomType::factory()->create(['branch_id' => $this->branch->id, 'base_rate' => 10000]);
    $room = Room::factory()->create([
        'branch_id' => $this->branch->id, 'room_type_id' => $roomType->id,
        'number' => '101', 'status' => 'available', 'is_active' => true,
    ]);

    $stay = app(AvailabilityService::class)->reserve(
        $this->branch, $roomType, $this->today, Carbon::parse($this->today)->addDays(2)->toDateString(),
        [
            'guest_name' => 'No Show', 'adults' => 1, 'children' => 0,
            'room_rate' => 10000, 'total_amount' => 20000,
            'status' => 'confirmed', 'source' => 'direct', 'payment_status' => 'pending',
        ],
        $room->id, (string) Str::uuid(),
    );

    $stay->update(['status' => 'no_show', 'no_show_fee_minor' => 10000]);

    $findings = $this->service->scanBranch($this->branch, $this->today);
    $codes = collect($findings)->pluck('rule_code')->all();

    expect($codes)->toContain('noshow_fee_skip');
});

it('confirms findings only through auditor action', function () {
    seedBaseline($this->folio, $this->today, 20, 1);
    seedVoidDay($this->folio, $this->today, 20, 12);

    $finding = collect($this->service->scanBranch($this->branch, $this->today))
        ->firstWhere('rule_code', 'void_rate');

    $confirmed = $this->service->confirm($finding, $this->user);

    expect($confirmed->status)->toBe(AnomalyFinding::STATUS_CONFIRMED);

    // A confirmed finding never re-raises, even on repeat scans.
    $again = $this->service->scanBranch($this->branch, $this->today);

    expect(collect($again)->pluck('rule_code')->all())->not->toContain('void_rate');
});
