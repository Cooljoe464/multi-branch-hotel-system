<?php

use App\Http\Resources\BranchResource;
use App\Http\Resources\GuestResource;
use App\Http\Resources\ReservationResource;
use App\Http\Resources\RoomResource;
use App\Http\Resources\RoomTypeResource;
use App\Models\Branch;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('serializes reservation attributes correctly', function () {
    $branch = Branch::factory()->create();
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);
    $room = Room::factory()->create(['branch_id' => $branch->id, 'room_type_id' => $roomType->id]);
    $guest = Guest::factory()->create();

    $reservation = Reservation::factory()->create([
        'branch_id' => $branch->id,
        'room_id' => $room->id,
        'room_type_id' => $roomType->id,
        'guest_id' => $guest->id,
        'room_rate' => 15000,
        'total_amount' => 45000,
        'amount_paid' => 20000,
    ]);

    $resource = new ReservationResource($reservation);
    $array = $resource->toArray(request());

    expect($array['id'])->toBe($reservation->id)
        ->and($array['confirmation_number'])->toBe($reservation->confirmation_number)
        ->and($array['status'])->toBe($reservation->status)
        ->and($array['room_rate'])->toBe(15000)
        ->and($array['total_amount'])->toBe(45000)
        ->and($array['amount_paid'])->toBe(20000)
        ->and($array['nights'])->toBeInt()
        ->and($array['check_in_date'])->toBeString()
        ->and($array['check_out_date'])->toBeString();
});

it('includes loaded relationships when eager loaded', function () {
    $branch = Branch::factory()->create();
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);
    $room = Room::factory()->create(['branch_id' => $branch->id, 'room_type_id' => $roomType->id]);
    $guest = Guest::factory()->create();

    $reservation = Reservation::factory()->create([
        'branch_id' => $branch->id,
        'room_id' => $room->id,
        'room_type_id' => $roomType->id,
        'guest_id' => $guest->id,
    ]);

    $reservation->load(['branch', 'room', 'roomType', 'guest']);

    $resource = new ReservationResource($reservation);
    $array = $resource->toArray(request());

    expect($array['branch'])->toBeInstanceOf(BranchResource::class)
        ->and($array['room'])->toBeInstanceOf(RoomResource::class)
        ->and($array['room_type'])->toBeInstanceOf(RoomTypeResource::class)
        ->and($array['guest'])->toBeInstanceOf(GuestResource::class);
});

it('omits relationships when not loaded', function () {
    $reservation = Reservation::factory()->create();

    $resource = new ReservationResource($reservation);
    $array = $resource->toArray(request());

    expect($array['branch'])->toBeNull()
        ->and($array['room'])->toBeNull()
        ->and($array['guest'])->toBeNull();
});

it('serializes dates as strings', function () {
    $reservation = Reservation::factory()->create([
        'check_in_date' => '2026-09-15',
        'check_out_date' => '2026-09-18',
    ]);

    $resource = new ReservationResource($reservation);
    $array = $resource->toArray(request());

    expect($array['check_in_date'])->toBe('2026-09-15')
        ->and($array['check_out_date'])->toBe('2026-09-18');
});

it('serializes array fields correctly', function () {
    $reservation = Reservation::factory()->create([
        'special_requests' => ['late check-in', 'extra pillows'],
    ]);

    $resource = new ReservationResource($reservation);
    $array = $resource->toArray(request());

    expect($array['special_requests'])->toBeArray()
        ->and($array['special_requests'])->toHaveCount(2);
});
