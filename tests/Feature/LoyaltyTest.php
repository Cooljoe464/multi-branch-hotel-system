<?php

use App\Exceptions\AvailabilityException;
use App\Http\Controllers\ConsentController;
use App\Models\Branch;
use App\Models\Consent;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyLedger;
use App\Models\PostStaySurvey;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\FolioService;
use App\Services\LoyaltyService;
use Carbon\Carbon;
use Database\Seeders\ChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
    $this->service = app(LoyaltyService::class);
});

function loyalStay(int $nights = 3, ?Guest $guest = null): Reservation
{
    $guest ??= Guest::factory()->create();
    $in = Carbon::now()->subDays($nights + 1)->toDateString();
    $out = Carbon::now()->subDay()->toDateString();

    return Reservation::factory()->checkedOut()->create([
        'branch_id' => test()->branch->id,
        'room_type_id' => test()->roomType->id,
        'guest_id' => $guest->id,
        'check_in_date' => $in,
        'check_out_date' => $out,
    ]);
}

it('earns once per stay even across double checkout', function () {
    $stay = loyalStay(3);

    $first = $this->service->earn($stay);
    $second = $this->service->earn($stay->fresh());

    // Member rate: 1 point per night.
    expect($first->delta)->toBe(3);
    expect($second->id)->toBe($first->id);
    expect(LoyaltyLedger::count())->toBe(1);
    expect(LoyaltyAccount::where('guest_id', $stay->guest_id)->first()->points)->toBe(3);
});

it('upgrades tiers exactly at the threshold', function () {
    $guest = Guest::factory()->create();
    loyalStay(9, $guest);
    $tenth = loyalStay(1, $guest);

    $this->service->earn($tenth);

    expect(LoyaltyAccount::where('guest_id', $guest->id)->first()->tier)->toBe('silver');
});

it('refuses overspend and serializes redeems', function () {
    $stay = loyalStay(2);
    $this->service->earn($stay);

    $account = LoyaltyAccount::where('guest_id', $stay->guest_id)->firstOrFail();
    $folio = Folio::where('reservation_id', $stay->id)->first();

    if (! $folio) {
        $folio = (new FolioService)->createFolio($this->branch->id, $stay->id);
    }

    expect(fn () => $this->service->redeem($account, $folio, 999, $this->user))
        ->toThrow(AvailabilityException::class, 'Only 2 points available');

    $entry = $this->service->redeem($account->fresh(), $folio, 2, $this->user, 'redeem-1');

    expect($entry->delta)->toBe(-2);
    expect($account->fresh()->points)->toBe(0);

    // Same key replays the original entry instead of double-spending.
    expect($this->service->redeem($account->fresh(), $folio, 2, $this->user, 'redeem-1')->id)->toBe($entry->id);
    expect($account->fresh()->points)->toBe(0);
});

it('earns through HTTP checkout and opens a survey shell', function () {
    $guest = Guest::factory()->create();

    $in = Carbon::now()->subDay()->toDateString();
    $out = Carbon::now()->addDay()->toDateString();

    $stay = Reservation::factory()->checkedIn()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'room_id' => $this->room->id,
        'guest_id' => $guest->id,
        'check_in_date' => $in,
        'check_out_date' => $out,
    ]);

    $this->actingAs($this->user)
        ->post(route('reservations.check-out', $stay))
        ->assertRedirect();

    expect(LoyaltyLedger::where('reason', 'stay_earn')->count())->toBe(1);
    expect(PostStaySurvey::where('reservation_id', $stay->id)->count())->toBe(1);
});

it('excludes no-consent guests from campaign audiences', function () {
    $optedIn = Guest::factory()->create();
    $optedOut = Guest::factory()->create();
    $silent = Guest::factory()->create();

    Consent::create(['guest_id' => $optedIn->id, 'channel' => 'email', 'purpose' => 'marketing', 'granted' => true, 'at' => now()]);
    Consent::create(['guest_id' => $optedOut->id, 'channel' => 'email', 'purpose' => 'marketing', 'granted' => false, 'at' => now()]);

    $audience = ConsentController::audience('email', 'marketing');

    expect($audience)->toBe([$optedIn->id]);
    expect($audience)->not->toContain($optedOut->id, $silent->id);
});

it('updates surveys in place with fresh sentiment', function () {
    $stay = loyalStay(2);

    $this->post(route('surveys.store', $stay), ['nps' => 9, 'answers' => ['liked' => 'The wonderful pool was amazing']])
        ->assertRedirect();

    $first = PostStaySurvey::where('reservation_id', $stay->id)->firstOrFail();

    expect($first->sentiment)->toBeGreaterThan(0.5);

    $this->post(route('surveys.store', $stay), ['nps' => 2, 'answers' => ['improve' => 'Terrible dirty nightmare, refund now']])
        ->assertRedirect();

    expect(PostStaySurvey::where('reservation_id', $stay->id)->count())->toBe(1);

    $updated = PostStaySurvey::where('reservation_id', $stay->id)->firstOrFail();

    expect($updated->nps)->toBe(2)
        ->and($updated->sentiment)->toBeLessThan(0.5);
});
