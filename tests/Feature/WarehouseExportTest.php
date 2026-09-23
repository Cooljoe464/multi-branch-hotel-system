<?php

use App\Events\WarehouseExportReady;
use App\Jobs\WarehouseExportJob;
use App\Models\Branch;
use App\Models\JournalEntry;
use App\Models\Reservation;
use App\Models\RevenueSnapshot;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\WarehouseManifest;
use App\Services\AvailabilityService;
use App\Services\BusinessDateService;
use App\Services\WarehouseExporter;
use Database\Seeders\ChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('r2');
    $this->branch = Branch::factory()->create(['timezone' => 'Africa/Lagos']);
    $this->user = $this->makeAdminUser($this->branch);
    $this->seed(ChartSeeder::class);
    $this->businessDate = app(BusinessDateService::class)->current($this->branch)->business_date->toDateString();

    $roomType = RoomType::factory()->create(['branch_id' => $this->branch->id, 'base_rate' => 10000]);
    Room::factory()->create(['branch_id' => $this->branch->id, 'room_type_id' => $roomType->id, 'number' => '101', 'status' => 'available', 'is_active' => true]);

    app(AvailabilityService::class)->reserve(
        $this->branch, $roomType,
        $this->businessDate, Carbon::parse($this->businessDate)->addDays(2)->toDateString(),
        [
            'guest_name' => 'Warehouse Guest', 'guest_email' => 'wh@example.com', 'guest_phone' => '+2348000000001',
            'adults' => 2, 'children' => 0, 'room_rate' => 10000, 'total_amount' => 20000,
            'status' => 'confirmed', 'source' => 'direct', 'payment_status' => 'pending',
        ],
        null, (string) Str::uuid(),
    );
});

it('exports row counts equal to the database', function () {
    $result = (new WarehouseExporter)->export($this->businessDate);
    $manifest = $result['manifest'];

    expect($manifest->status)->toBe(WarehouseManifest::STATUS_READY)
        ->and($manifest->row_counts['reservations'])->toBe(Reservation::count())
        ->and($manifest->row_counts['journal_entries'])->toBe(JournalEntry::count())
        ->and($manifest->row_counts['revenue_snapshots'])->toBe(RevenueSnapshot::count())
        ->and((new WarehouseExporter)->verify($manifest))->toBeTrue();
});

it('re-runs the same date with identical checksums and no duplicate files', function () {
    $exporter = new WarehouseExporter;
    $first = $exporter->export($this->businessDate);
    $second = $exporter->export($this->businessDate);

    expect($second['manifest']->id)->toBe($first['manifest']->id)
        ->and($second['manifest']->checksums)->toBe($first['manifest']->checksums)
        ->and(Storage::disk('r2')->allFiles("warehouse/dt={$this->businessDate}"))->toHaveCount(4);
});

it('keeps guest PII out of the artefacts', function () {
    (new WarehouseExporter)->export($this->businessDate);

    $contents = gzdecode((string) Storage::disk('r2')->get("warehouse/dt={$this->businessDate}/reservations.csv.gz"));

    expect($contents)->toBeString()
        ->and($contents)->not->toContain('Warehouse Guest')
        ->and($contents)->not->toContain('wh@example.com')
        ->and($contents)->not->toContain('+2348000000001')
        ->and($contents)->toContain('guest_name_hash');
});

it('fails verification for a tampered file', function () {
    $manifest = (new WarehouseExporter)->export($this->businessDate)['manifest'];

    Storage::disk('r2')->put("warehouse/dt={$this->businessDate}/reservations.csv.gz", gzencode('tampered') ?: '');

    expect((new WarehouseExporter)->verify($manifest))->toBeFalse();
});

it('runs through the job and broadcasts readiness', function () {
    Event::fake([WarehouseExportReady::class]);

    (new WarehouseExportJob($this->businessDate))->handle();

    Event::assertDispatched(WarehouseExportReady::class);
});
