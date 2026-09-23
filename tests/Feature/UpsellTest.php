<?php

use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Models\DoorLockGateway;
use App\Models\ReservationMove;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Transaction;
use App\Models\UpsellAcceptance;
use App\Models\UpsellOffer;
use App\Services\AvailabilityService;
use App\Services\UpsellService;
use Carbon\Carbon;
use Database\Seeders\ChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
        'number' => '101',
        'status' => 'occupied',
    ]);
    $this->spare = Room::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'number' => '102',
        'status' => 'available',
    ]);
    DoorLockGateway::factory()->duowin()->forBranch($this->branch)->create();
    Http::fake(['*' => Http::response(['card_id' => 'CARD-1'], 200)]);

    $this->in = Carbon::now()->subDay()->toDateString();
    $this->out = Carbon::now()->addDays(2)->toDateString();

    $this->reservation = app(AvailabilityService::class)->reserve(
        $this->branch, $this->roomType, $this->in, $this->out,
        [
            'guest_name' => 'Upsell Guest',
            'adults' => 2,
            'children' => 0,
            'room_rate' => 20000,
            'total_amount' => 60000,
            'status' => 'checked_in',
            'source' => 'direct',
            'payment_status' => 'pending',
        ],
        $this->room->id, (string) Str::uuid(),
    );

    $this->late = UpsellOffer::create([
        'branch_id' => $this->branch->id,
        'kind' => 'late_checkout',
        'rules' => ['fee_minor' => 5000, 'inventory_guard' => true],
        'active' => true,
    ]);
    $this->service = app(UpsellService::class);
});

function suiteType(): RoomType
{
    $type = RoomType::factory()->create(['branch_id' => test()->branch->id, 'base_rate' => 50000, 'code' => 'STE']);
    Room::factory()->create([
        'branch_id' => test()->branch->id,
        'room_type_id' => $type->id,
        'number' => '201',
        'status' => 'available',
    ]);

    return $type;
}

it('quotes late checkout when free and posts the fee once', function () {
    $quotes = $this->service->quote($this->branch, $this->reservation);

    $late = collect($quotes)->firstWhere('kind', 'late_checkout');

    expect($late['eligible'])->toBeTrue()
        ->and($late['fee_minor'])->toBe(5000);

    $first = $this->service->accept($this->reservation, $this->late, $this->user, 'up-1');
    $second = $this->service->accept($this->reservation->fresh(), $this->late, $this->user, 'up-1');

    expect($second->id)->toBe($first->id)
        ->and($first->fee_minor)->toBe(5000);
    expect(Transaction::where('category', 'late_checkout_fee')->where('is_voided', false)->count())->toBe(1);
});

it('refuses late checkout when the room type sold out tonight', function () {
    app(AvailabilityService::class)->reserve(
        $this->branch, $this->roomType, $this->in, $this->out,
        [
            'guest_name' => 'Second Guest', 'adults' => 1, 'children' => 0,
            'room_rate' => 20000, 'total_amount' => 60000,
            'status' => 'confirmed', 'source' => 'direct', 'payment_status' => 'pending',
        ],
        $this->spare->id, (string) Str::uuid(),
    );

    $quotes = $this->service->quote($this->branch, $this->reservation->fresh());
    $late = collect($quotes)->firstWhere('kind', 'late_checkout');

    expect($late['eligible'])->toBeFalse();

    try {
        $this->service->accept($this->reservation->fresh(), $this->late, $this->user, 'up-sold');
        $this->fail('Expected a sold-out refusal.');
    } catch (AvailabilityException $e) {
        expect($e->availabilityCode)->toBe('ROOM_SOLD_TONIGHT');
    }
});

it('upgrades by moving inventory and posts the difference once', function () {
    $suite = suiteType();

    $upgrade = UpsellOffer::create([
        'branch_id' => $this->branch->id,
        'kind' => 'upgrade',
        'rules' => ['fee_minor' => 8000, 'target_room_type_id' => $suite->id],
        'active' => true,
    ]);

    $first = $this->service->accept($this->reservation, $upgrade, $this->user, 'up-grade-1');

    expect($first->fee_minor)->toBe(8000);
    expect($this->reservation->fresh()->room_id)->not->toBe($this->room->id);
    expect(Transaction::where('category', 'upgrade_fee')->where('is_voided', false)->count())->toBe(1);

    $second = $this->service->accept($this->reservation->fresh(), $upgrade, $this->user, 'up-grade-1');

    expect($second->id)->toBe($first->id);
    expect(Transaction::where('category', 'upgrade_fee')->where('is_voided', false)->count())->toBe(1);
    expect(ReservationMove::where('reservation_id', $this->reservation->id)->count())->toBe(1);
});

it('grants free only with permission and refuses expired offers', function () {
    $frontDesk = $this->makeAdminUser($this->branch);
    $frontDesk->removeRole('Global Admin');
    $frontDesk->assignRole('Front Desk');
    $frontDesk->givePermissionTo('reservations.view');

    $this->actingAs($frontDesk)
        ->post(
            route('upsells.accept', $this->reservation),
            ['offer_id' => $this->late->id, 'grant_free' => true],
            ['X-Idempotency-Key' => 'up-free-1'],
        )
        ->assertForbidden();

    $this->actingAs($this->user)
        ->post(
            route('upsells.accept', $this->reservation),
            ['offer_id' => $this->late->id, 'grant_free' => true],
            ['X-Idempotency-Key' => 'up-free-2'],
        )
        ->assertRedirect();

    $free = UpsellAcceptance::where('idempotency_key', 'up-free-2')->firstOrFail();

    expect($free->fee_minor)->toBe(0);

    // Expired offers refuse with 410 Gone.
    $response = $this->actingAs($this->user)->post(
        route('upsells.accept', $this->reservation),
        ['offer_id' => $this->late->id, 'expires_at' => Carbon::now()->subHour()->toDateTimeString()],
        ['X-Idempotency-Key' => 'up-expired-2'],
    );

    expect($response->status())->toBe(410);
});
