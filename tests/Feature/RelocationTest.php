<?php

use App\Models\Branch;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\RelocationService;
use Database\Seeders\ChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('AC-034 creates an auditable relocation and compensation folio item', function () {
    $source = Branch::factory()->create();
    $destination = Branch::factory()->create(['tenant_id' => $source->tenant_id]);
    $roomType = RoomType::factory()->create(['branch_id' => $source->id]);
    $destinationType = RoomType::factory()->create(['branch_id' => $destination->id, 'max_occupancy' => 4]);
    $room = Room::factory()->create(['branch_id' => $destination->id, 'room_type_id' => $destinationType->id, 'status' => 'available']);
    $reservation = Reservation::factory()->forBranch($source->id)->create([
        'room_type_id' => $roomType->id,
        'overbooked' => true,
        'adults' => 2,
        'children' => 0,
    ]);
    $this->seed(ChartSeeder::class);

    $relocation = (new RelocationService)->create($reservation, $destination, $room, 5000, 'No physical room at arrival.', 'relocate-1');

    expect($relocation->status)->toBe('pending')
        ->and($relocation->compensation_transaction_id)->not->toBeNull();
    expect($reservation->fresh()->metadata['relocation_required'])->toBeTrue()
        ->and($reservation->fresh()->metadata['relocation_id'])->toBe($relocation->id);
    $this->assertDatabaseHas('transactions', [
        'id' => $relocation->compensation_transaction_id,
        'category' => 'relocation_compensation',
        'type' => 'credit',
        'amount' => 5000,
    ]);
});

it('AC-035 rejects relocation to an unavailable destination room', function () {
    $source = Branch::factory()->create();
    $destination = Branch::factory()->create(['tenant_id' => $source->tenant_id]);
    $roomType = RoomType::factory()->create(['branch_id' => $source->id]);
    $destinationType = RoomType::factory()->create(['branch_id' => $destination->id, 'max_occupancy' => 4]);
    $room = Room::factory()->create(['branch_id' => $destination->id, 'room_type_id' => $destinationType->id, 'status' => 'occupied']);
    $reservation = Reservation::factory()->forBranch($source->id)->create([
        'room_type_id' => $roomType->id,
        'overbooked' => true,
        'adults' => 2,
        'children' => 0,
    ]);

    expect(fn () => (new RelocationService)->create($reservation, $destination, $room, 0, 'No room.', 'relocate-2'))->toThrow('destination room is unavailable');
});

it('AC-035 flags an arriving overbooked stay when the property has no physical room available', function () {
    $branch = Branch::factory()->create();
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);
    $room = Room::factory()->create([
        'branch_id' => $branch->id,
        'room_type_id' => $roomType->id,
        'status' => 'occupied',
    ]);
    $occupiedStay = Reservation::factory()->checkedIn()->create([
        'branch_id' => $branch->id,
        'room_type_id' => $roomType->id,
        'room_id' => $room->id,
        'check_in_date' => now()->toDateString(),
        'check_out_date' => now()->addDay()->toDateString(),
    ]);
    $arrivingStay = Reservation::factory()->forBranch($branch->id)->create([
        'room_type_id' => $roomType->id,
        'room_id' => null,
        'status' => 'confirmed',
        'overbooked' => true,
        'check_in_date' => now()->toDateString(),
        'check_out_date' => now()->addDay()->toDateString(),
    ]);
    $user = $this->makeAdminUser($branch);

    $this->actingAs($user)
        ->from('/reservations/'.$arrivingStay->id)
        ->post('/reservations/'.$arrivingStay->id.'/check-in')
        ->assertSessionHasErrors(['room_id']);

    expect($arrivingStay->fresh()->metadata['relocation_required'])->toBeTrue()
        ->and($occupiedStay->fresh()->status)->toBe('checked_in');
});

it('requires a positive compensation credit for an approved relocation', function () {
    $source = Branch::factory()->create();
    $destination = Branch::factory()->create(['tenant_id' => $source->tenant_id]);
    $roomType = RoomType::factory()->create(['branch_id' => $source->id]);
    $destinationType = RoomType::factory()->create(['branch_id' => $destination->id, 'max_occupancy' => 4]);
    $room = Room::factory()->create(['branch_id' => $destination->id, 'room_type_id' => $destinationType->id, 'status' => 'available']);
    $reservation = Reservation::factory()->forBranch($source->id)->create(['room_type_id' => $roomType->id, 'overbooked' => true]);

    expect(fn () => (new RelocationService)->create($reservation, $destination, $room, 0, 'No room.', 'relocate-3'))
        ->toThrow('A positive guest compensation amount is required.');
});
