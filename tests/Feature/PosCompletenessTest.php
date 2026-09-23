<?php

use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Models\HappyHour;
use App\Models\KotItem;
use App\Models\Outlet;
use App\Models\PosCharge;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\AvailabilityService;
use App\Services\PosService;
use Carbon\Carbon;
use Database\Seeders\ChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['timezone' => 'Africa/Lagos', 'tax_rate' => 0]);
    $this->user = $this->makeAdminUser($this->branch);
    $this->seed(ChartSeeder::class);
    $this->roomType = RoomType::factory()->create(['branch_id' => $this->branch->id, 'base_rate' => 20000]);
    $this->room = Room::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'status' => 'available',
    ]);
    $this->outlet = Outlet::factory()->create(['branch_id' => $this->branch->id, 'code' => 'POOLBAR']);
    $this->reservation = app(AvailabilityService::class)->reserve(
        $this->branch, $this->roomType,
        Carbon::now()->subDay()->toDateString(), Carbon::now()->addDays(2)->toDateString(),
        [
            'guest_name' => 'POS Guest', 'adults' => 2, 'children' => 0,
            'room_rate' => 20000, 'total_amount' => 60000,
            'status' => 'checked_in', 'source' => 'direct', 'payment_status' => 'pending',
        ],
        $this->room->id, (string) Str::uuid(),
    );
    $this->service = app(PosService::class);
});

function openTab(string $course = 'main'): PosCharge
{
    return test()->service->openTab(
        test()->branch, test()->outlet, test()->reservation,
        null, 2, test()->user,
    );
}

it('splits 10000 three ways with exact integer shares', function () {
    $tab = openTab();
    $tab = $this->service->addItems($tab, [
        ['name' => 'Platter', 'quantity' => 1, 'price' => 10000, 'course' => 'main'],
    ]);

    expect($tab->total)->toBe(10000);

    $children = $this->service->splitBill($tab, [
        ['percent_bps' => 3334],
        ['percent_bps' => 3333],
        ['percent_bps' => 3333],
    ]);

    expect($children[0]->total)->toBe(3334)
        ->and($children[1]->total)->toBe(3333)
        ->and($children[2]->total)->toBe(3333)
        ->and(array_sum([$children[0]->total, $children[1]->total, $children[2]->total]))->toBe(10000);
    expect($tab->fresh()->status)->toBe('split');

    // A second split is refused: one split set per tab.
    expect(fn () => $this->service->splitBill($tab->fresh(), [['percent_bps' => 10000]]))
        ->toThrow(AvailabilityException::class, 'already split');
});

it('fires courses into ordered KOTs', function () {
    $tab = openTab();
    $tab = $this->service->addItems($tab, [
        ['name' => 'Soup', 'quantity' => 1, 'price' => 3000, 'course' => 'starter'],
        ['name' => 'Steak', 'quantity' => 1, 'price' => 7000, 'course' => 'main'],
    ]);

    $starter = $this->service->fireCourse($tab, 'starter', $this->user);
    $main = $this->service->fireCourse($tab->fresh(), 'main', $this->user);

    expect($starter)->toHaveCount(1)
        ->and($main)->toHaveCount(1)
        ->and($starter[0]->course)->toBe('starter')
        ->and($main[0]->course)->toBe('main')
        ->and($main[0]->id)->toBeGreaterThan($starter[0]->id);
    expect(KotItem::where('pos_charge_id', $tab->id)->count())->toBe(2);

    expect(fn () => $this->service->fireCourse($tab->fresh(), 'starter', $this->user))
        ->toThrow(AvailabilityException::class, 'nothing left to fire');
});

it('prices happy-hour boundaries server-side and freezes them', function () {
    // Boundary times are constructed in the branch timezone: the engine
    // evaluates windows in branch time, not server time.
    $friday = Carbon::parse('next friday', 'Africa/Lagos')->toDateString();

    HappyHour::create([
        'outlet_id' => $this->outlet->id,
        'branch_id' => $this->branch->id,
        'cron_window' => 'FRI 17:00-19:00',
        'discount_bps' => 2000,
        'active' => true,
    ]);

    // One minute before the window: full price.
    Carbon::setTestNow(Carbon::parse("{$friday} 16:59:00", 'Africa/Lagos'));
    $early = $this->service->addItems(openTab(), [
        ['name' => 'Cocktail', 'quantity' => 1, 'price' => 10000],
    ]);

    expect($early->total)->toBe(10000)
        ->and($early->items[0]['happy_hour_id'] ?? null)->toBeNull();

    // Inside the window: 20% off, frozen on the line.
    Carbon::setTestNow(Carbon::parse("{$friday} 17:00:00", 'Africa/Lagos'));
    $happy = $this->service->addItems(openTab(), [
        ['name' => 'Cocktail', 'quantity' => 1, 'price' => 10000],
    ]);

    expect($happy->total)->toBe(8000)
        ->and($happy->items[0]['happy_discount_minor'] ?? 0)->toBe(2000);

    // Window end is exclusive: full price again.
    Carbon::setTestNow(Carbon::parse("{$friday} 19:00:00", 'Africa/Lagos'));
    $late = $this->service->addItems(openTab(), [
        ['name' => 'Cocktail', 'quantity' => 1, 'price' => 10000],
    ]);

    expect($late->total)->toBe(10000);

    Carbon::setTestNow();
});

it('replays offline batches exactly once', function () {
    $payloads = [];
    foreach (range(1, 5) as $i) {
        $payloads[] = [
            'offline_nonce' => "offline-test-{$i}",
            'reservation_id' => $this->reservation->id,
            'items' => [['name' => "Snack {$i}", 'quantity' => 1, 'price' => 1000]],
        ];
    }

    $first = $this->service->replayOffline($this->outlet, $payloads, $this->user);

    expect($first)->toBe(['posted' => 5, 'skipped' => 0, 'errors' => []]);
    expect(PosCharge::whereNotNull('offline_nonce')->count())->toBe(5);

    $second = $this->service->replayOffline($this->outlet, $payloads, $this->user);

    expect($second)->toBe(['posted' => 0, 'skipped' => 5, 'errors' => []]);
    expect(PosCharge::whereNotNull('offline_nonce')->count())->toBe(5);
});
