<?php

use App\Http\Resources\BranchResource;
use App\Http\Resources\RoomResource;
use App\Http\Resources\RoomTypeResource;
use App\Models\Branch;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('serializes room attributes correctly', function () {
    $branch = Branch::factory()->create();
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);

    $room = Room::factory()->create([
        'branch_id' => $branch->id,
        'room_type_id' => $roomType->id,
        'number' => '301',
        'floor' => '3',
        'wing' => 'east',
        'status' => 'available',
        'is_accessible' => true,
        'is_smoking' => false,
        'is_active' => true,
    ]);

    $resource = new RoomResource($room);
    $array = $resource->toArray(request());

    expect($array['id'])->toBe($room->id)
        ->and($array['number'])->toBe('301')
        ->and($array['floor'])->toBe('3')
        ->and($array['wing'])->toBe('east')
        ->and($array['status'])->toBe('available')
        ->and($array['is_accessible'])->toBeTrue()
        ->and($array['is_smoking'])->toBeFalse()
        ->and($array['is_active'])->toBeTrue();
});

it('includes loaded relationships when eager loaded', function () {
    $branch = Branch::factory()->create();
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);

    $room = Room::factory()->create([
        'branch_id' => $branch->id,
        'room_type_id' => $roomType->id,
    ]);

    $room->load(['branch', 'roomType']);

    $resource = new RoomResource($room);
    $array = $resource->toArray(request());

    expect($array['branch'])->toBeInstanceOf(BranchResource::class)
        ->and($array['room_type'])->toBeInstanceOf(RoomTypeResource::class);
});

it('omits relationships when not loaded', function () {
    $room = Room::factory()->create();

    $resource = new RoomResource($room);
    $array = $resource->toArray(request());

    expect($array['branch'])->toBeNull()
        ->and($array['room_type'])->toBeNull()
        ->and($array['current_reservation'])->toBeNull();
});

it('serializes nullable fields correctly', function () {
    $room = Room::factory()->create([
        'floor' => null,
        'wing' => null,
        'notes' => null,
    ]);

    $resource = new RoomResource($room);
    $array = $resource->toArray(request());

    expect($array['floor'])->toBeNull()
        ->and($array['wing'])->toBeNull()
        ->and($array['notes'])->toBeNull();
});
