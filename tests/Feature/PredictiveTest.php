<?php

use App\Exceptions\AvailabilityException;
use App\Models\Asset;
use App\Models\AssetHealthScore;
use App\Models\Branch;
use App\Models\Guest;
use App\Models\HkSchedule;
use App\Models\HousekeepingTask;
use App\Models\MaintenanceTicket;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\BusinessDateService;
use App\Services\HkSchedulerService;
use App\Services\PredictiveMaintenanceService;
use Database\Seeders\ChartSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['timezone' => 'Africa/Lagos']);
    $this->user = $this->makeAdminUser($this->branch);
    $this->seed(ChartSeeder::class);
    $this->today = app(BusinessDateService::class)->current($this->branch)->business_date->toDateString();
    $this->roomType = RoomType::factory()->create(['branch_id' => $this->branch->id, 'base_rate' => 10000]);
    $this->maintenance = new PredictiveMaintenanceService;
    $this->scheduler = new HkSchedulerService;
});

function failingAsset(object $test, int $tickets = 3): Asset
{
    $room = Room::factory()->create([
        'branch_id' => $test->branch->id, 'room_type_id' => $test->roomType->id,
        'number' => 'AC-'.random_int(100, 999), 'status' => 'available', 'is_active' => true,
    ]);

    $asset = Asset::create([
        'branch_id' => $test->branch->id,
        'name' => 'AC Unit',
        'category' => 'hvac',
        'room_id' => $room->id,
        'installed_on' => Carbon::parse($test->today)->subYears(6)->toDateString(),
        'pm_schedule' => ['every_days' => 30],
        'last_pm_at' => Carbon::parse($test->today)->subDays(60)->toDateString(),
    ]);

    foreach (range(1, $tickets) as $i) {
        MaintenanceTicket::create([
            'branch_id' => $test->branch->id,
            'asset_id' => $asset->id,
            'room_id' => $room->id,
            'reported_by' => $test->user->id,
            'category' => 'hvac',
            'priority' => 'normal',
            'status' => 'completed',
            'title' => "Rattle {$i}",
            'description' => 'Noise complaint',
            'created_at' => Carbon::parse($test->today)->subDays($i * 5),
            'updated_at' => Carbon::parse($test->today)->subDays($i * 5),
        ]);
    }

    return $asset;
}

function housekeeper(object $test, string $name): User
{
    $user = User::factory()->create(['branch_id' => $test->branch->id, 'name' => $name]);
    $user->assignRole('Housekeeper');

    return $user;
}

it('drafts one PM work order for a repeat-failure asset and never duplicates', function () {
    $asset = failingAsset($this);

    $first = $this->maintenance->scoreBranch($this->branch, $this->today);

    expect($first['drafts'])->toBe(1);

    $draft = MaintenanceTicket::where('asset_id', $asset->id)->where('status', 'draft')->firstOrFail();

    expect($draft->metadata['pm_draft'] ?? false)->toBeTrue();

    $second = $this->maintenance->scoreBranch($this->branch, $this->today);

    expect($second['drafts'])->toBe(0)
        ->and(MaintenanceTicket::where('asset_id', $asset->id)->where('status', 'draft')->count())->toBe(1);
});

it('never takes rooms out of order no matter the risk', function () {
    $asset = failingAsset($this, 10);
    $roomId = $asset->room_id;

    $this->maintenance->scoreBranch($this->branch, $this->today);

    $score = AssetHealthScore::where('asset_id', $asset->id)->latest('scored_on')->firstOrFail();

    expect($score->failure_prob)->toBeGreaterThan(0.6)
        ->and(Room::find($roomId)?->status)->toBe('available');
});

it('schedules under caps with VIP arrivals first and keeps pins', function () {
    $this->seed(RoleSeeder::class);
    $aisha = housekeeper($this, 'Aisha');
    $bola = housekeeper($this, 'Bola');
    $tomorrow = Carbon::parse($this->today)->addDay()->toDateString();

    $rooms = [];
    foreach (range(1, 8) as $i) {
        $rooms[] = Room::factory()->create([
            'branch_id' => $this->branch->id, 'room_type_id' => $this->roomType->id,
            'number' => "2{$i}1", 'status' => 'dirty', 'is_active' => true, 'floor' => '2',
        ]);
    }

    $vipRoom = Room::factory()->create([
        'branch_id' => $this->branch->id, 'room_type_id' => $this->roomType->id,
        'number' => '301', 'status' => 'available', 'is_active' => true, 'floor' => '3',
    ]);

    $guest = Guest::factory()->create(['vip_status' => 'gold']);
    Reservation::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'room_id' => $vipRoom->id,
        'guest_id' => $guest->id,
        'guest_name' => 'VIP Guest',
        'check_in_date' => $tomorrow,
        'check_out_date' => Carbon::parse($tomorrow)->addDays(2)->toDateString(),
        'status' => 'confirmed',
        'room_rate' => 10000,
        'total_amount' => 20000,
    ]);

    $schedule = $this->scheduler->plan($this->branch, $tomorrow, 30);
    $assignments = $schedule->assignments ?? [];

    // VIP arrival inspection is planned despite the tight cap.
    expect($assignments)->toHaveKey((string) $vipRoom->id)
        ->and($assignments[(string) $vipRoom->id]['attendant_id'])->not->toBeNull();

    $loads = [];
    foreach ($assignments as $row) {
        if (is_int($row['attendant_id'] ?? null)) {
            $loads[$row['attendant_id']] = ($loads[$row['attendant_id']] ?? 0) + $row['credits'];
        }
    }

    expect($loads)->not->toBeEmpty();

    foreach ($loads as $load) {
        expect($load)->toBeLessThanOrEqual(30);
    }

    // Pin the VIP room to Bola; re-plan keeps the pin.
    $pinned = $assignments;
    $pinned[(string) $vipRoom->id] = array_merge($assignments[(string) $vipRoom->id], ['attendant_id' => $bola->id, 'pinned' => true]);
    $schedule->update(['assignments' => $pinned]);

    $replanned = $this->scheduler->plan($this->branch, $tomorrow, 30);
    $rows = $replanned->assignments ?? [];

    expect($rows[(string) $vipRoom->id]['attendant_id'])->toBe($bola->id)
        ->and($rows[(string) $vipRoom->id]['pinned'] ?? false)->toBeTrue();
});

it('publishes once and refuses non-GM publishers', function () {
    $this->seed(RoleSeeder::class);
    housekeeper($this, 'Aisha');
    $tomorrow = Carbon::parse($this->today)->addDay()->toDateString();

    Room::factory()->create([
        'branch_id' => $this->branch->id, 'room_type_id' => $this->roomType->id,
        'number' => '201', 'status' => 'dirty', 'is_active' => true, 'floor' => '2',
    ]);

    $schedule = $this->scheduler->plan($this->branch, $tomorrow, 100);

    $certified = $this->user;

    try {
        $housekeeper = User::where('branch_id', $this->branch->id)->where('name', 'Aisha')->firstOrFail();
        $this->scheduler->publish($schedule, $housekeeper);
        $this->fail('Expected an HK_PUBLISH exception.');
    } catch (AvailabilityException $e) {
        expect($e->availabilityCode)->toBe('HK_PUBLISH');
    }

    $this->scheduler->publish($schedule, $certified);
    $this->scheduler->publish($schedule->fresh() ?? $schedule, $certified);

    expect(HousekeepingTask::forBranch($this->branch->id)->open()->count())->toBe(1)
        ->and(HkSchedule::find($schedule->id)?->status)->toBe(HkSchedule::STATUS_PUBLISHED);
});
