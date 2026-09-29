<?php

use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Models\DailyLedger;
use App\Models\NlQueryLog;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\BusinessDateService;
use App\Services\NlReportingService;
use App\Services\Reporting\SqlGuard;
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
    $this->service = new NlReportingService;

    foreach (range(0, 6) as $i) {
        $day = Carbon::parse($this->today)->subDays($i)->toDateString();

        DailyLedger::create([
            'branch_id' => $this->branch->id,
            'business_date' => $day,
            'status' => 'completed',
            'rooms_posted' => 10,
            'total_room_revenue' => 100000,
            'total_tax' => 0,
            'total_other_charges' => 5000,
            'total_payments' => 0,
            'net_revenue' => 105000,
        ]);
    }
});

it('answers revenue questions with exact row values', function () {
    $result = $this->service->ask($this->branch, $this->user, 'revenue last 7 days');

    expect($result['status'])->toBe(NlQueryLog::STATUS_COMPLETED)
        ->and($result['columns'])->toBe(['day', 'room_revenue', 'other_revenue', 'payments'])
        ->and($result['total'])->toBe(7)
        ->and($result['rows'][0][1])->toBe(100000)
        ->and($result['sql'])->toContain('daily_ledgers');
});

it('scopes answers to the asking property', function () {
    $other = Branch::factory()->create(['timezone' => 'Africa/Lagos']);

    DailyLedger::create([
        'branch_id' => $other->id,
        'business_date' => $this->today,
        'status' => 'completed',
        'rooms_posted' => 99,
        'total_room_revenue' => 999000,
        'total_tax' => 0,
        'total_other_charges' => 0,
        'total_payments' => 0,
        'net_revenue' => 999000,
    ]);

    $result = $this->service->ask($this->branch, $this->user, 'revenue today');

    expect($result['total'])->toBe(1)
        ->and($result['rows'][0][1])->toBe(100000);
});

it('refuses disallowed tables and unknown questions', function () {
    try {
        SqlGuard::validate('select password from users where branch_id = 1');
        $this->fail('Expected an NL_QUERY_REFUSED exception.');
    } catch (AvailabilityException $e) {
        expect($e->availabilityCode)->toBe('NL_QUERY_REFUSED');
    }

    try {
        $this->service->ask($this->branch, $this->user, 'write a poem about the pool');
        $this->fail('Expected an NL_QUERY_UNKNOWN exception.');
    } catch (AvailabilityException $e) {
        expect($e->availabilityCode)->toBe('NL_QUERY_UNKNOWN');
    }
});

it('masks guest PII without the permission', function () {
    $roomType = RoomType::factory()->create(['branch_id' => $this->branch->id, 'base_rate' => 10000]);
    $room = Room::factory()->create([
        'branch_id' => $this->branch->id, 'room_type_id' => $roomType->id,
        'number' => '101', 'status' => 'available', 'is_active' => true,
    ]);

    app(AvailabilityService::class)->reserve(
        $this->branch, $roomType, $this->today, Carbon::parse($this->today)->addDays(2)->toDateString(),
        [
            'guest_name' => 'Private Guest', 'guest_email' => 'private@example.com',
            'adults' => 2, 'children' => 0, 'room_rate' => 10000, 'total_amount' => 20000,
            'status' => 'confirmed', 'source' => 'direct', 'payment_status' => 'pending',
        ],
        $room->id, (string) Str::uuid(),
    );

    $plain = User::factory()->create(['branch_id' => $this->branch->id]);

    $masked = $this->service->ask($this->branch, $plain, 'arrivals today');
    $open = $this->service->ask($this->branch, $this->user, 'arrivals today');

    expect($masked['rows'][0][1])->not->toContain('Private Guest')
        ->and($open['rows'][0][1])->toContain('Private Guest');
});

it('runs slow queries async with identical results on poll', function () {
    $pending = $this->service->ask($this->branch, $this->user, 'revenue last 7 days', null, true);

    expect($pending['status'])->toBe(NlQueryLog::STATUS_PENDING);

    $polled = $this->service->poll($pending['key'], $this->user);

    expect($polled['status'] ?? null)->toBe(NlQueryLog::STATUS_COMPLETED);

    $sync = $this->service->ask($this->branch, $this->user, 'revenue last 7 days');

    expect($sync['status'])->toBe('cached')
        ->and($sync['rows'])->toBe($polled['rows'] ?? null)
        ->and(NlQueryLog::where('branch_id', $this->branch->id)->count())->toBe(1);
});
