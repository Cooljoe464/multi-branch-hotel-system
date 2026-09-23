<?php

use App\Jobs\GenerateStatutoryReportJob;
use App\Models\Branch;
use App\Models\Guest;
use App\Models\RegistrationCard;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\StatutoryReport;
use App\Services\AvailabilityService;
use App\Services\StatutoryReportService;
use Carbon\Carbon;
use Database\Seeders\ChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['timezone' => 'Africa/Lagos']);
    $this->user = $this->makeAdminUser($this->branch);
    $this->seed(ChartSeeder::class);
    $this->roomType = RoomType::factory()->create(['branch_id' => $this->branch->id, 'base_rate' => 20000]);
    $this->room = Room::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'status' => 'available',
    ]);
    Storage::fake('r2');

    $this->from = Carbon::now()->subDays(5)->toDateString();
    $this->to = Carbon::now()->subDays(2)->toDateString();
    $this->service = app(StatutoryReportService::class);
});

function statutoryStay(bool $withId = true): Reservation
{
    $guest = Guest::factory()->create([
        'nationality' => $withId ? 'NG' : null,
        'id_type' => $withId ? 'passport' : null,
        'id_number' => $withId ? 'P111222' : null,
    ]);

    $room = Room::factory()->create([
        'branch_id' => test()->branch->id,
        'room_type_id' => test()->roomType->id,
        'status' => 'available',
    ]);

    $reservation = app(AvailabilityService::class)->reserve(
        test()->branch, test()->roomType,
        test()->from, Carbon::parse(test()->from)->addDay()->toDateString(),
        [
            'guest_id' => $guest->id,
            'guest_name' => $guest->full_name,
            'guest_email' => $guest->email,
            'adults' => 1,
            'children' => 0,
            'room_rate' => 20000,
            'total_amount' => 20000,
            'status' => 'checked_out',
            'source' => 'direct',
            'payment_status' => 'paid',
        ],
        $room->id, (string) Str::uuid(),
    );

    if ($withId) {
        RegistrationCard::create([
            'reservation_id' => $reservation->id,
            'guest_name' => $guest->full_name,
            'id_type' => 'passport',
            'id_number' => 'P111222',
            'signature_image_url' => 'sig.png',
        ]);
    }

    return $reservation;
}

it('generates exact rows with incomplete IDs in the annex', function () {
    statutoryStay(true);
    statutoryStay(false);

    $report = $this->service->generate($this->branch, 'immigration', $this->from, $this->to, $this->user);

    // Sync queues build inline; async queues leave it queued for the job.
    expect($report->fresh()->status)->toBeIn(['queued', 'ready']);

    (new GenerateStatutoryReportJob($report->id))->handle();
    $report->refresh();

    expect($report->status)->toBe('ready')
        ->and($report->summary['rows'] ?? 0)->toBe(2)
        ->and($report->summary['annex'] ?? 0)->toBe(1)
        ->and($report->file_hash)->not->toBeNull();

    expect(Storage::disk('r2')->exists($report->file_path))->toBeTrue();

    $built = $this->service->buildRows($this->branch, 'immigration', $this->from, $this->to);

    expect($built['rows'][0]['id_number'] ?? null)->toBe('P111222')
        ->and($built['annex'][0]['missing'] ?? [])->toContain('id_number');
});

it('reuses the same file for the same range', function () {
    statutoryStay(true);

    $first = $this->service->generate($this->branch, 'police', $this->from, $this->to, $this->user);
    (new GenerateStatutoryReportJob($first->id))->handle();

    $second = $this->service->generate($this->branch, 'police', $this->from, $this->to, $this->user);

    expect($second->id)->toBe($first->id);
    expect($second->fresh()->file_hash)->toBe($first->fresh()->file_hash);
    expect(StatutoryReport::count())->toBe(1);
});
