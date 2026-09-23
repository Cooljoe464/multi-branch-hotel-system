<?php

use App\Jobs\SnapshotRevenueJob;
use App\Models\Branch;
use App\Models\Budget;
use App\Models\DailyLedger;
use App\Models\JournalEntry;
use App\Models\RevenueSnapshot;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\AvailabilityService;
use App\Services\BusinessDateService;
use App\Services\RevenueAnalyticsService;
use Carbon\Carbon;
use Database\Seeders\ChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['timezone' => 'Africa/Lagos']);
    $this->user = $this->makeAdminUser($this->branch);
    $this->seed(ChartSeeder::class);
    $this->today = app(BusinessDateService::class)->current($this->branch)->business_date->toDateString();
    $this->roomType = RoomType::factory()->create(['branch_id' => $this->branch->id, 'base_rate' => 10000]);
    foreach (range(1, 2) as $i) {
        Room::factory()->create([
            'branch_id' => $this->branch->id,
            'room_type_id' => $this->roomType->id,
            'status' => 'available',
            'is_active' => true,
        ]);
    }
    $this->yesterday = Carbon::parse($this->today)->subDay()->toDateString();
});

function completedLedger(Branch $branch, string $date, int $posted, int $room, int $other): void
{
    DailyLedger::create([
        'branch_id' => $branch->id,
        'business_date' => $date,
        'status' => 'completed',
        'rooms_posted' => $posted,
        'total_room_revenue' => $room,
        'total_tax' => 0,
        'total_other_charges' => $other,
        'total_payments' => 0,
        'net_revenue' => $room + $other,
    ]);
}

it('computes exact KPIs from actuals plus on-the-books pickup', function () {
    completedLedger($this->branch, $this->yesterday, 2, 20000, 5000);

    JournalEntry::create([
        'branch_id' => $this->branch->id,
        'business_date' => $this->yesterday,
        'event' => 'operating.expense',
        'debit_account' => '5200',
        'credit_account' => '1100',
        'amount_minor' => 3000,
    ]);

    $in = Carbon::parse($this->today)->addDays(2)->toDateString();
    $out = Carbon::parse($this->today)->addDays(4)->toDateString();

    app(AvailabilityService::class)->reserve(
        $this->branch, $this->roomType, $in, $out,
        [
            'guest_name' => 'Future Guest', 'adults' => 2, 'children' => 0,
            'room_rate' => 10000, 'total_amount' => 20000,
            'status' => 'confirmed', 'source' => 'direct', 'payment_status' => 'pending',
        ],
        null, (string) Str::uuid(),
    );

    (new SnapshotRevenueJob)->handle();

    $metrics = (new RevenueAnalyticsService)->metrics($this->branch, $this->yesterday, $out);

    // 2 rooms × 6 stay dates (inclusive) = 12 available; 2 actual + 2 OTB sold.
    expect($metrics['rooms_available'])->toBe(12)
        ->and($metrics['rooms_sold'])->toBe(4)
        ->and($metrics['room_revenue_minor'])->toBe(40000)
        ->and($metrics['total_revenue_minor'])->toBe(45000)
        ->and($metrics['gop_expense_minor'])->toBe(3000)
        ->and($metrics['adr_minor'])->toBe(10000)
        ->and($metrics['revpar_minor'])->toBe(3333)
        ->and($metrics['trevpar_minor'])->toBe(3750)
        ->and($metrics['goppar_minor'])->toBe(3500)
        ->and($metrics['occupancy_bps'])->toBe(3333)
        ->and($metrics['by_source']['direct']['nights'] ?? 0)->toBe(2)
        ->and($metrics['by_segment']['retail']['nights'] ?? 0)->toBe(2);
});

it('measures pickup against the snapshot from seven days ago', function () {
    $stay = Carbon::parse($this->today)->addDays(10)->toDateString();

    RevenueSnapshot::create([
        'branch_id' => $this->branch->id,
        'stay_date' => $stay,
        'snapshot_date' => Carbon::parse($this->today)->subDays(7)->toDateString(),
        'rooms_available' => 2,
        'rooms_sold' => 1,
        'room_revenue_minor' => 10000,
        'total_revenue_minor' => 10000,
        'gop_expense_minor' => 0,
    ]);

    $out = Carbon::parse($stay)->addDay()->toDateString();

    foreach (['Pace One', 'Pace Two'] as $guest) {
        app(AvailabilityService::class)->reserve(
            $this->branch, $this->roomType, $stay, $out,
            [
                'guest_name' => $guest, 'adults' => 2, 'children' => 0,
                'room_rate' => 10000, 'total_amount' => 10000,
                'status' => 'confirmed', 'source' => 'direct', 'payment_status' => 'pending',
            ],
            null, (string) Str::uuid(),
        );
    }

    (new SnapshotRevenueJob)->handle();

    $metrics = (new RevenueAnalyticsService)->metrics($this->branch, $stay, $stay);
    $pace = collect($metrics['pace'])->firstWhere('stay_date', $stay);

    expect($pace['sold_then'])->toBe(1)
        ->and($pace['sold_now'])->toBe(2)
        ->and($pace['pickup'])->toBe(1)
        ->and($pace['revenue_now_minor'] - $pace['revenue_then_minor'])->toBe(10000);
});

it('rebuilds idempotently without duplicating snapshot rows', function () {
    $first = (new SnapshotRevenueJob)->handle();
    $second = (new SnapshotRevenueJob)->handle();

    expect($second['rows'])->toBe($first['rows']);
    expect(RevenueSnapshot::forBranch($this->branch->id)
        ->where('snapshot_date', $this->today)
        ->count())->toBe($first['rows']);
});

it('reports OTB vs budget variance with the correct sign', function () {
    // Anchor inside the budget month regardless of month boundaries.
    $anchor = Carbon::parse($this->today)->startOfMonth()->addDays(10);
    $in = $anchor->toDateString();
    $out = $anchor->copy()->addDays(2)->toDateString();
    $month = $anchor->copy()->startOfMonth()->toDateString();

    Budget::create([
        'branch_id' => $this->branch->id,
        'month' => $month,
        'room_nights_target' => 100,
        'revenue_target_minor' => 5000000,
    ]);

    $in = $anchor->toDateString();
    $out = $anchor->copy()->addDays(2)->toDateString();

    app(AvailabilityService::class)->reserve(
        $this->branch, $this->roomType, $in, $out,
        [
            'guest_name' => 'Budget Guest', 'adults' => 2, 'children' => 0,
            'room_rate' => 10000, 'total_amount' => 20000,
            'status' => 'confirmed', 'source' => 'direct', 'payment_status' => 'pending',
        ],
        null, (string) Str::uuid(),
    );

    (new SnapshotRevenueJob)->handle();

    $metrics = (new RevenueAnalyticsService)->metrics($this->branch, $in, $out);
    $budget = collect($metrics['budgets'])->firstWhere('month', $month);

    // Behind target: variances negative.
    expect($budget['room_nights_otb'])->toBe(2)
        ->and($budget['nights_variance'])->toBe(-98)
        ->and($budget['revenue_otb_minor'])->toBe(20000)
        ->and($budget['revenue_variance_minor'])->toBe(-4980000);
});

it('denominates snapshots in the branch currency at snapshot time', function () {
    (new SnapshotRevenueJob)->handle();

    $rows = RevenueSnapshot::forBranch($this->branch->id)->get();

    expect($rows)->not->toBeEmpty()
        ->and($rows->pluck('currency_code')->unique()->all())->toBe([$this->branch->currency_code]);
});
