<?php

use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Models\DoorLockGateway;
use App\Models\ReservationMove;
use App\Models\ReservationNight;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Task;
use App\Models\Transaction;
use App\Services\AvailabilityService;
use App\Services\FolioService;
use App\Services\RoomMoveService;
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
    $this->oldRoom = Room::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'number' => '101',
        'status' => 'occupied',
    ]);
    $this->newRoom = Room::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'number' => '102',
        'status' => 'available',
    ]);
    DoorLockGateway::factory()->duowin()->forBranch($this->branch)->create();

    $this->in = Carbon::now()->subDay()->toDateString();
    $this->out = Carbon::now()->addDays(2)->toDateString();

    $this->reservation = app(AvailabilityService::class)->reserve(
        $this->branch, $this->roomType, $this->in, $this->out,
        [
            'guest_name' => 'Mover Guest',
            'adults' => 2,
            'children' => 0,
            'room_rate' => 20000,
            'total_amount' => 60000,
            'status' => 'checked_in',
            'source' => 'direct',
            'payment_status' => 'pending',
        ],
        $this->oldRoom->id, (string) Str::uuid(),
    );
    $this->service = app(RoomMoveService::class);
});

it('moves future nights, preserves history and folio totals', function () {
    Http::fake(['*' => Http::response(['card_id' => 'CARD-1'], 200)]);

    $folio = (new FolioService)->createFolio($this->branch->id, $this->reservation->id, null, 'Guest Folio');
    (new FolioService)->postDebit($folio, 'room_rate', 'Room charge: 101', 20000, $this->user->id, 750);

    $before = (int) Transaction::where('folio_id', $folio->id)->where('is_voided', false)->sum('amount');

    $move = $this->service->move($this->reservation, $this->newRoom, $this->user, (string) Str::uuid(), ['reason' => 'Leaking AC']);

    expect($move->from_room_id)->toBe($this->oldRoom->id)
        ->and($move->to_room_id)->toBe($this->newRoom->id)
        ->and($move->key_reissued)->toBeTrue()
        ->and($move->reason)->toBe('Leaking AC');

    $today = Carbon::today()->toDateString();

    $past = ReservationNight::forReservation($this->reservation->id)
        ->where('stay_date', '<', $today)->pluck('room_id')->unique()->all();
    $future = ReservationNight::forReservation($this->reservation->id)
        ->where('stay_date', '>=', $today)->pluck('room_id')->unique()->all();

    expect($past)->toBe([$this->oldRoom->id]);
    expect($future)->toBe([$this->newRoom->id]);
    expect($this->reservation->fresh()->room_id)->toBe($this->newRoom->id);
    expect($this->oldRoom->fresh()->status)->toBe('dirty');
    expect($this->newRoom->fresh()->status)->toBe('occupied');

    expect((int) Transaction::where('folio_id', $folio->id)->where('is_voided', false)->sum('amount'))->toBe($before);

    expect(Task::where('room_id', $this->oldRoom->id)->where('type', 'turnover')->exists())->toBeTrue();
    expect(Task::where('room_id', $this->newRoom->id)->where('type', 'inspection')->exists())->toBeTrue();
});

it('rejects moves into occupied rooms', function () {
    $this->newRoom->update(['status' => 'occupied']);

    expect(fn () => $this->service->move($this->reservation, $this->newRoom->fresh(), $this->user))
        ->toThrow(AvailabilityException::class, 'not available');

    expect($this->reservation->fresh()->room_id)->toBe($this->oldRoom->id);
    expect(ReservationMove::count())->toBe(0);
});

it('rolls everything back when key reissue fails', function () {
    Http::fake(['*' => Http::response('boom', 500)]);

    try {
        $this->service->move($this->reservation, $this->newRoom, $this->user);
        $this->fail('Expected a key failure.');
    } catch (AvailabilityException $e) {
        expect($e->availabilityCode)->toBe('KEY_REISSUE_FAILED');
    }

    expect(ReservationMove::count())->toBe(0);
    expect($this->reservation->fresh()->room_id)->toBe($this->oldRoom->id);
    expect($this->newRoom->fresh()->status)->toBe('available');
    expect(ReservationNight::forReservation($this->reservation->id)->pluck('room_id')->unique()->all())
        ->toBe([$this->oldRoom->id]);

    // Over HTTP the same failure surfaces as a 502 with no side effects.
    $this->actingAs($this->user)
        ->post(route('reservations.move', $this->reservation), ['room_id' => $this->newRoom->id])
        ->assertStatus(502);

    expect(ReservationMove::count())->toBe(0);
});

it('serializes concurrent moves and replays idempotently', function () {
    Http::fake(['*' => Http::response(['card_id' => 'CARD-1'], 200)]);

    $third = Room::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'number' => '103',
        'status' => 'available',
    ]);

    $key = (string) Str::uuid();
    $first = $this->service->move($this->reservation, $this->newRoom, $this->user, $key);

    // Same key replays the original row even though the target is taken.
    $replay = $this->service->move($this->reservation->fresh(), $this->newRoom->fresh(), $this->user, $key);

    expect($replay->id)->toBe($first->id);
    expect(ReservationMove::count())->toBe(1);

    // A second distinct move to another free room succeeds.
    $second = $this->service->move($this->reservation->fresh(), $third, $this->user, (string) Str::uuid());

    expect($second->from_room_id)->toBe($this->newRoom->id);
    expect($second->to_room_id)->toBe($third->id);
    expect($this->oldRoom->fresh()->status)->toBe('dirty');
});
