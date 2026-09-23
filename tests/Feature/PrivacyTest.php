<?php

use App\Exceptions\AvailabilityException;
use App\Jobs\PurgeGuestJob;
use App\Jobs\RetentionRunJob;
use App\Models\Branch;
use App\Models\Guest;
use App\Models\GuestIdentityDocument;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Transaction;
use App\Services\AvailabilityService;
use App\Services\DsarService;
use App\Services\FolioService;
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
    $this->service = app(DsarService::class);
});

function settledGuest(): Guest
{
    $guest = Guest::factory()->create(['email' => 'erase.me@example.com', 'phone' => '+2348012345678']);

    $in = Carbon::now()->subDays(5)->toDateString();
    $out = Carbon::now()->subDays(3)->toDateString();

    $reservation = app(AvailabilityService::class)->reserve(
        test()->branch, test()->roomType, $in, $out,
        [
            'guest_id' => $guest->id,
            'guest_name' => 'Erase Me',
            'guest_email' => 'erase.me@example.com',
            'adults' => 1,
            'children' => 0,
            'room_rate' => 20000,
            'total_amount' => 40000,
            'status' => 'checked_out',
            'source' => 'direct',
            'payment_status' => 'paid',
        ],
        test()->room->id, (string) Str::uuid(),
    );

    $folio = (new FolioService)->createFolio(test()->branch->id, $reservation->id, null, 'Guest Folio');
    (new FolioService)->postDebit($folio, 'room_rate', 'Room charge', 40000, test()->user->id, 750);
    (new FolioService)->recordPayment($folio, 43000, 'card', test()->user->id, 'PAY-1');
    (new FolioService)->checkout($folio, $reservation->id);

    return $guest->fresh();
}

it('refuses erasure with an open folio and purges cleanly after settlement', function () {
    Storage::fake('r2');

    $guest = Guest::factory()->create(['email' => 'stay@example.com']);

    $in = Carbon::now()->subDay()->toDateString();
    $out = Carbon::now()->addDays(2)->toDateString();

    $reservation = app(AvailabilityService::class)->reserve(
        $this->branch, $this->roomType, $in, $out,
        [
            'guest_id' => $guest->id,
            'guest_name' => 'Stay Guest',
            'guest_email' => 'stay@example.com',
            'adults' => 1,
            'children' => 0,
            'room_rate' => 20000,
            'total_amount' => 60000,
            'status' => 'checked_in',
            'source' => 'direct',
            'payment_status' => 'pending',
        ],
        $this->room->id, (string) Str::uuid(),
    );

    $folio = (new FolioService)->createFolio($this->branch->id, $reservation->id, null, 'Guest Folio');
    (new FolioService)->postDebit($folio, 'room_rate', 'Room charge', 20000, $this->user->id, 750);

    $dsar = $this->service->intake($this->branch, $guest, 'erasure', $this->user);

    try {
        $this->service->fulfill($dsar, $this->user);
        $this->fail('Expected an open-folio refusal.');
    } catch (AvailabilityException $e) {
        expect($e->availabilityCode)->toBe('OPEN_FOLIO');
    }

    // Settle + checkout, then purge converges.
    (new FolioService)->recordPayment($folio, 21500, 'card', $this->user->id, 'PAY-2');
    (new FolioService)->checkout($folio, $reservation->id);
    $reservation->update(['status' => 'checked_out']);

    GuestIdentityDocument::create([
        'guest_id' => $guest->id,
        'doc_type' => 'passport',
        'doc_number' => 'P987654',
        'ocr_status' => 'manual',
    ]);

    $this->service->fulfill($dsar->fresh(), $this->user);
    $receipt = (new PurgeGuestJob($dsar->fresh()->id))->handle();

    expect($receipt['reservations_scrubbed'] ?? 0)->toBe(1);

    $guest->refresh();

    expect($guest->email)->toContain('deleted.local')
        ->and($guest->phone)->toBeNull()
        ->and(GuestIdentityDocument::where('guest_id', $guest->id)->count())->toBe(0);

    // Amounts intact (debit + payment legs), journal balanced.
    expect((int) Transaction::where('folio_id', $folio->id)->where('is_voided', false)->sum('amount'))->toBe(41500);
    expect(Reservation::find($reservation->id)->guest_email)->toBeNull();

    // Re-run converges to the same receipt shape.
    $again = (new PurgeGuestJob($dsar->fresh()->id))->handle();

    expect($again['guest_id'] ?? null)->toBe($guest->id);
});

it('exports access bundles scoped to the requesting guest', function () {
    Storage::fake('r2');

    $guest = settledGuest();
    $other = Guest::factory()->create(['email' => 'other@example.com']);

    $dsar = $this->service->intake($this->branch, $guest, 'access', $this->user);
    $fulfilled = $this->service->fulfill($dsar, $this->user);

    expect($fulfilled->status)->toBe('fulfilled');

    $bundle = $this->service->exportBundle($guest->fresh());

    expect($bundle['guest']['email'])->toBe('erase.me@example.com')
        ->and($bundle['counts']['reservations'])->toBe(1);
    expect(json_encode($bundle))->not->toContain('other@example.com');
});

it('runs retention idempotently and never touches folio lines', function () {
    Storage::fake('r2');

    Storage::disk('r2')->put('identity/1/old.jpg', 'bytes');

    $doc = GuestIdentityDocument::create([
        'guest_id' => Guest::factory()->create()->id,
        'doc_type' => 'passport',
        'scan_path' => 'identity/1/old.jpg',
        'ocr_status' => 'done',
    ]);
    GuestIdentityDocument::where('id', $doc->id)->update(['created_at' => Carbon::now()->subDays(400)]);

    $first = (new RetentionRunJob)->handle();
    $second = (new RetentionRunJob)->handle();

    expect($first['scans_cleared'])->toBe(1);
    expect(Storage::disk('r2')->exists('identity/1/old.jpg'))->toBeFalse();
    expect(GuestIdentityDocument::query()->first()->scan_path)->toBeNull();
    expect($second['scans_cleared'])->toBe(0);
});
