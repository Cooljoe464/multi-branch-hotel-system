<?php

use App\Exceptions\AvailabilityException;
use App\Http\Controllers\ConsentController;
use App\Jobs\LoyaltyRecalcJob;
use App\Models\Branch;
use App\Models\Consent;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyLedger;
use App\Models\LoyaltyTier;
use App\Models\PostStaySurvey;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\FolioService;
use App\Services\LoyaltyService;
use Carbon\Carbon;
use Database\Seeders\ChartSeeder;
use Illuminate\Console\Scheduling\Schedule;
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

it('lets a platform owner save and remove loyalty tiers but not a property admin', function () {
    $owner = $this->makeAdminUser($this->branch);
    $owner->forceFill(['is_global_admin' => true])->save();

    $this->actingAs($owner)
        ->post("/branches/{$this->branch->id}/crm/tiers", ['name' => 'diamond', 'threshold_nights' => 100, 'earn_bps' => 20000])
        ->assertRedirect();

    $tier = LoyaltyTier::where('name', 'diamond')->firstOrFail();
    expect($tier->threshold_nights)->toBe(100);

    $this->actingAs($owner)
        ->post("/branches/{$this->branch->id}/crm/tiers", ['name' => 'diamond', 'threshold_nights' => 120, 'earn_bps' => 20000]);
    expect(LoyaltyTier::where('name', 'diamond')->count())->toBe(1)
        ->and($tier->fresh()->threshold_nights)->toBe(120);

    $propertyAdmin = $this->makeAdminUser($this->branch);
    $propertyAdmin->forceFill(['is_global_admin' => false])->save();

    $this->actingAs($propertyAdmin)
        ->post("/branches/{$this->branch->id}/crm/tiers", ['name' => 'rogue', 'threshold_nights' => 1, 'earn_bps' => 1])
        ->assertForbidden();
    $this->actingAs($propertyAdmin)
        ->delete("/branches/{$this->branch->id}/crm/tiers/{$tier->id}")
        ->assertForbidden();

    $this->actingAs($owner)->delete("/branches/{$this->branch->id}/crm/tiers/{$tier->id}")->assertRedirect();
    expect(LoyaltyTier::where('name', 'diamond')->exists())->toBeFalse();

    $member = LoyaltyTier::where('name', 'member')->first();
    if ($member !== null) {
        $this->actingAs($owner)->delete("/branches/{$this->branch->id}/crm/tiers/{$member->id}")->assertStatus(422);
    }
});

it('promotes members across a threshold in the nightly recalc job', function () {
    $guest = Guest::factory()->create();
    $account = $this->service->enroll($guest);
    loyalStay(12, $guest);

    $result = (new LoyaltyRecalcJob)->handle($this->service);

    expect($result['upgraded'])->toBeGreaterThanOrEqual(1)
        ->and($account->fresh()->tier)->toBe('silver');
});

it('schedules the loyalty recalc job nightly', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->description, 'LoyaltyRecalcJob'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('45 3 * * *');
});
