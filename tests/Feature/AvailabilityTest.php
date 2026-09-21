<?php

use App\Exceptions\AvailabilityException;
use App\Jobs\BackfillRoomInventoryJob;
use App\Models\Branch;
use App\Models\Reservation;
use App\Models\ReservationNight;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\RoomTypeInventory;
use App\Services\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create();
    $this->user = $this->makeAdminUser($this->branch);
    $this->roomType = RoomType::factory()->create(['branch_id' => $this->branch->id]);
    $this->room = Room::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'status' => 'available',
    ]);
    $this->service = app(AvailabilityService::class);
});

function bookingAttributes(): array
{
    return [
        'guest_name' => 'Engine Guest',
        'adults' => 2,
        'children' => 0,
        'room_rate' => 25000,
        'total_amount' => 50000,
        'status' => 'confirmed',
        'source' => 'direct',
        'payment_status' => 'pending',
    ];
}

it('quotes per-night sellable counts', function () {
    $quote = $this->service->quote($this->branch, $this->roomType, '2026-11-01', '2026-11-03');

    expect($quote['available'])->toBeTrue()
        ->and($quote['sellable_per_night'])->toBe(['2026-11-01' => 1, '2026-11-02' => 1])
        ->and($quote['unavailable_dates'])->toBe([]);
});

it('reserves inventory and lays one night per stay date', function () {
    $reservation = $this->service->reserve(
        $this->branch, $this->roomType, '2026-11-01', '2026-11-03',
        bookingAttributes(), $this->room->id, (string) Str::uuid(),
    );

    expect($reservation->room_id)->toBe($this->room->id)
        ->and($reservation->overbooked)->toBeFalse();

    expect(ReservationNight::forReservation($reservation->id)->count())->toBe(2);
    expect($this->service->sellableFor($this->branch, $this->roomType, '2026-11-01'))->toBe(0);

    $quote = $this->service->quote($this->branch, $this->roomType, '2026-11-01', '2026-11-03');
    expect($quote['available'])->toBeFalse()
        ->and($quote['unavailable_dates'])->toBe(['2026-11-01', '2026-11-02']);
});

it('rejects a second booking for the sold-out night', function () {
    $this->service->reserve(
        $this->branch, $this->roomType, '2026-11-01', '2026-11-03',
        bookingAttributes(), $this->room->id, (string) Str::uuid(),
    );

    // The type has exactly one physical room: the night is gone even
    // without requesting a specific room.
    $this->service->reserve(
        $this->branch, $this->roomType, '2026-11-01', '2026-11-02',
        bookingAttributes(), null, (string) Str::uuid(),
    );
})->throws(AvailabilityException::class, 'No availability');

it('rejects a double-booked physical room with ROOM_CONFLICT', function () {
    $room2 = Room::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'status' => 'available',
    ]);

    $this->service->reserve(
        $this->branch, $this->roomType, '2026-11-01', '2026-11-02',
        bookingAttributes(), $this->room->id, (string) Str::uuid(),
    );

    // Type still has a free unit (room2), but room 1 itself is held.
    expect(fn () => $this->service->reserve(
        $this->branch, $this->roomType, '2026-11-01', '2026-11-02',
        bookingAttributes(), $this->room->id, (string) Str::uuid(),
    ))->toThrow(AvailabilityException::class, 'already held');

    // room2 itself books fine.
    $second = $this->service->reserve(
        $this->branch, $this->roomType, '2026-11-01', '2026-11-02',
        bookingAttributes(), $room2->id, (string) Str::uuid(),
    );
    expect($second->room_id)->toBe($room2->id);
});

it('replays an idempotent reserve without consuming inventory twice', function () {
    $key = (string) Str::uuid();

    $first = $this->service->reserve(
        $this->branch, $this->roomType, '2026-11-01', '2026-11-03',
        bookingAttributes(), $this->room->id, $key,
    );
    $second = $this->service->reserve(
        $this->branch, $this->roomType, '2026-11-01', '2026-11-03',
        bookingAttributes(), $this->room->id, $key,
    );

    expect($second->id)->toBe($first->id);
    expect(Reservation::where('idempotency_key', $key)->count())->toBe(1);
    expect(ReservationNight::forReservation($first->id)->count())->toBe(2);
});

it('releases nights on cancel and allows rebooking', function () {
    $reservation = $this->service->reserve(
        $this->branch, $this->roomType, '2026-11-01', '2026-11-03',
        bookingAttributes(), $this->room->id, (string) Str::uuid(),
    );

    $this->actingAs($this->user)->post("/reservations/{$reservation->id}/cancel")->assertRedirect();

    expect(ReservationNight::forReservation($reservation->id)->count())->toBe(0);
    expect($this->service->sellableFor($this->branch, $this->roomType, '2026-11-01'))->toBe(1);

    // Releasing twice is a no-op.
    $this->service->release($reservation->fresh());
    expect($this->service->sellableFor($this->branch, $this->roomType, '2026-11-01'))->toBe(1);
});

it('allows overbooking only with permission and a reason', function () {
    $this->service->reserve(
        $this->branch, $this->roomType, '2026-11-01', '2026-11-02',
        bookingAttributes(), $this->room->id, (string) Str::uuid(),
    );

    // No reason, no permission: sold out.
    expect(fn () => $this->service->reserve(
        $this->branch, $this->roomType, '2026-11-01', '2026-11-02',
        bookingAttributes(), null, (string) Str::uuid(),
    ))->toThrow(AvailabilityException::class, 'No availability');

    // Reason but no permission: forbidden.
    $frontDesk = $this->makeAdminUser($this->branch);
    $frontDesk->removeRole('Global Admin');
    $frontDesk->assignRole('Front Desk');

    expect(fn () => $this->service->reserve(
        $this->branch, $this->roomType, '2026-11-01', '2026-11-02',
        bookingAttributes(), null, (string) Str::uuid(), $frontDesk, 'VIP arrival',
    ))->toThrow(AvailabilityException::class, 'Overbooking requires');

    // Permission + reason: flagged overbook.
    $overbooked = $this->service->reserve(
        $this->branch, $this->roomType, '2026-11-01', '2026-11-02',
        bookingAttributes(), null, (string) Str::uuid(), $this->user, 'VIP arrival',
    );

    expect($overbooked->overbooked)->toBeTrue()
        ->and($overbooked->metadata['overbook_reason'])->toBe('VIP arrival');
});

it('serves the quote endpoint to permitted roles', function () {
    $this->actingAs($this->user)
        ->getJson("/branches/{$this->branch->id}/availability?room_type_id={$this->roomType->id}&check_in=2026-11-01&check_out=2026-11-03")
        ->assertOk()
        ->assertJsonPath('available', true);

    $user = $this->makeAdminUser($this->branch);
    $user->removeRole('Global Admin');

    $this->actingAs($user)
        ->getJson("/branches/{$this->branch->id}/availability?room_type_id={$this->roomType->id}&check_in=2026-11-01&check_out=2026-11-03")
        ->assertForbidden();
});

it('backfills inventory from rooms and active reservations', function () {
    $reservation = Reservation::factory()->create([
        'branch_id' => $this->branch->id,
        'room_id' => $this->room->id,
        'room_type_id' => $this->roomType->id,
        'status' => 'confirmed',
        'check_in_date' => '2026-10-01',
        'check_out_date' => '2026-10-03',
    ]);

    $stats = app(BackfillRoomInventoryJob::class)->handle();

    expect($stats['nights'])->toBe(2);
    expect(ReservationNight::forReservation($reservation->id)->count())->toBe(2);

    $row = RoomTypeInventory::forRoomType($this->roomType->id)->where('stay_date', '2026-10-01')->first();
    expect($row->sold)->toBe(1)->and($row->total_rooms)->toBe(1);

    // Re-running lays zero new nights.
    $again = app(BackfillRoomInventoryJob::class)->handle();
    expect($again['nights'])->toBe(0);
    expect(ReservationNight::forReservation($reservation->id)->count())->toBe(2);
});
