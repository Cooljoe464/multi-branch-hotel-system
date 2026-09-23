<?php

use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\GuestDedupService;
use App\Services\IdentityService;
use Carbon\Carbon;
use Database\Seeders\ChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
    $this->service = app(GuestDedupService::class);
});

function twinGuests(): array
{
    $dob = '1990-04-17';
    $a = Guest::factory()->create([
        'first_name' => 'John', 'last_name' => 'Doe',
        'email' => 'john.one@example.com', 'phone' => '+2348011111111',
        'date_of_birth' => $dob,
        'total_stays' => 2, 'total_nights' => 4, 'total_spent' => 80000,
    ]);
    $b = Guest::factory()->create([
        'first_name' => 'Jon', 'last_name' => 'Doe',
        'email' => 'john.two@example.com', 'phone' => '+2348011111111',
        'date_of_birth' => $dob,
        'total_stays' => 1, 'total_nights' => 2, 'total_spent' => 40000,
    ]);

    return [$a->fresh(), $b->fresh()];
}

function stayAttrs(string $guest, string $email): array
{
    return [
        'guest_name' => $guest,
        'guest_email' => $email,
        'adults' => 2,
        'children' => 0,
        'room_rate' => 20000,
        'total_amount' => 40000,
        'status' => 'confirmed',
        'source' => 'direct',
        'payment_status' => 'pending',
    ];
}

it('merges duplicates onto the survivor and redirects the retired', function () {
    [$survivor, $retired] = twinGuests();

    expect($survivor->dedup_hash)->toBe($retired->dedup_hash);

    $in = Carbon::now()->addDays(5)->toDateString();
    $out = Carbon::now()->addDays(7)->toDateString();

    app(AvailabilityService::class)->reserve(
        $this->branch, $this->roomType, $in, $out,
        array_merge(stayAttrs('John Doe', 'john.two@example.com'), ['guest_id' => $retired->id]),
        null, (string) Str::uuid(),
    );
    $retired->setPreference('comfort', 'pillow', 'soft');

    $link = $this->service->merge($survivor, $retired, ['phone' => 'retired'], $this->user);

    expect($retired->fresh()->master_guest_id)->toBe($survivor->id)
        ->and($survivor->fresh()->total_stays)->toBe(3)
        ->and($survivor->fresh()->total_spent)->toBe(120000)
        ->and($survivor->fresh()->reservations()->count())->toBe(1)
        ->and($survivor->fresh()->getPreference('comfort', 'pillow'))->toBe('soft');

    // Re-run returns the original link; concurrent merges collapse too.
    expect($this->service->merge($survivor->fresh(), $retired->fresh(), [], $this->user)->id)->toBe($link->id);

    $this->actingAs($this->user)
        ->get(route('guests.profile', $retired))
        ->assertRedirect(route('guests.profile', $survivor->fresh()));
});

it('blocks merges onto retired profiles and active stays', function () {
    [$a, $b] = twinGuests();
    $c = Guest::factory()->create(['first_name' => 'John', 'last_name' => 'Doe', 'phone' => '+2348011111111']);

    $this->service->merge($a, $b, [], $this->user);

    expect(fn () => $this->service->merge($b->fresh(), $c->fresh(), [], $this->user))
        ->toThrow(AvailabilityException::class, 'itself retired');

    $in = Carbon::now()->subDay()->toDateString();
    $out = Carbon::now()->addDays(2)->toDateString();

    app(AvailabilityService::class)->reserve(
        $this->branch, $this->roomType, $in, $out,
        array_merge(stayAttrs('Active Guest', 'active@example.com'), ['status' => 'checked_in']),
        $this->room->id, (string) Str::uuid(),
    );
    $activeGuest = Guest::factory()->create(['first_name' => 'Active', 'last_name' => 'Guest', 'phone' => '+2348099999999']);

    $checkedIn = Reservation::where('guest_email', 'active@example.com')->firstOrFail();
    $checkedIn->update(['guest_id' => $activeGuest->id]);

    expect(fn () => $this->service->merge($a->fresh(), $activeGuest->fresh(), [], $this->user))
        ->toThrow(AvailabilityException::class, 'active stay');
});

it('blocks DNR bookings and allows logged GM overrides', function () {
    $this->service->listDnr($this->branch, null, 'banned@example.com', 'Property damage 2024.', $this->user);

    $in = Carbon::now()->addDays(5)->toDateString();
    $out = Carbon::now()->addDays(7)->toDateString();

    $payload = array_merge(stayAttrs('Banned Guest', 'banned@example.com'), [
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'check_in_date' => $in,
        'check_out_date' => $out,
    ]);

    // Front desk without override: refused, no row.
    $frontDesk = $this->makeAdminUser($this->branch);
    $frontDesk->removeRole('Global Admin');
    $frontDesk->assignRole('Front Desk');

    $this->actingAs($frontDesk)->post(route('reservations.store'), $payload)
        ->assertSessionHasErrors();

    expect(Reservation::where('guest_email', 'banned@example.com')->count())->toBe(0);

    // Override without permission is still refused.
    $this->actingAs($frontDesk)
        ->post(route('reservations.store'), array_merge($payload, ['dnr_override_reason' => 'VIP insistence']))
        ->assertSessionHasErrors();

    // GM override with reason books + logs.
    $this->actingAs($this->user)
        ->post(route('reservations.store'), array_merge($payload, ['dnr_override_reason' => 'Resolved with management.']))
        ->assertRedirect();

    expect(Reservation::where('guest_email', 'banned@example.com')->count())->toBe(1);
});

it('encrypts identity numbers and fakes OCR without leaking PII', function () {
    Storage::fake('r2');
    config(['services.ocr.endpoint' => 'https://ocr.test/parse', 'services.ocr.key' => 'test-key']);
    Http::fake(['*' => Http::response(['doc_number' => 'A12345678', 'surname' => 'Doe'], 200)]);

    $logged = [];
    Log::listen(function (MessageLogged $event) use (&$logged) {
        $logged[] = json_encode([$event->message, $event->context]);
    });

    $guest = Guest::factory()->create(['email' => 'id@example.com']);
    $scan = UploadedFile::fake()->image('passport.jpg');

    // The sync queue runs OCR inside capture.
    $doc = (new IdentityService)->capture($guest, 'passport', 'A12345678', $scan, $this->user);

    $raw = $doc->getAttributes();

    expect($raw['doc_number_enc'] ?? null)->not->toBeNull()
        ->and($raw['doc_number_enc'] ?? null)->not->toContain('A12345678')
        ->and($doc->fresh()->doc_number)->toBe('A12345678')
        ->and($doc->fresh()->ocr_status)->toBe('done')
        ->and($doc->fresh()->ocr_result['surname'] ?? null)->toBe('Doe');

    foreach ($logged as $line) {
        expect($line)->not->toContain('A12345678');
    }

    $url = (new IdentityService)->scanUrl($doc->fresh(), $this->user);

    expect($url)->toStartWith('http');
});

it('refuses PII unmasking without permission', function () {
    Storage::fake('r2');

    $guest = Guest::factory()->create(['email' => 'secret@example.com']);
    $doc = (new IdentityService)->capture($guest, 'nin', '99998888777', null, $this->user);

    $housekeeper = User::factory()->create(['branch_id' => $this->branch->id]);
    $housekeeper->assignRole('Housekeeper');
    $housekeeper->givePermissionTo('guests.view');

    $this->actingAs($housekeeper)
        ->get(route('identity.reveal', $doc))
        ->assertForbidden();
});
