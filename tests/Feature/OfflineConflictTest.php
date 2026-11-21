<?php

use App\Models\Branch;
use App\Models\OfflineCommand;
use App\Models\OfflineProvisionalStay;
use App\Models\Room;
use App\Models\RoomType;
use App\Support\BranchTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $payload
 */
function offlineConflictPayload(Branch $branch, Room $room, string $guestName = 'Offline Guest'): array
{
    return [
        'room_id' => $room->id,
        'room_type_id' => $room->room_type_id,
        'guest_name' => $guestName,
        'check_in_date' => BranchTime::today($branch),
        'check_out_date' => BranchTime::now($branch)->addDay()->toDateString(),
    ];
}

it('lists pending offline room conflicts for the active property', function () {
    $branch = Branch::factory()->create();
    $user = $this->makeAdminUser($branch);
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);
    $room = Room::factory()->create(['branch_id' => $branch->id, 'room_type_id' => $roomType->id, 'status' => 'occupied']);

    $this->actingAs($user)->postJson('/api/v1/sync/push', [
        'device_id' => 'device-a',
        'commands' => [[
            'command_uuid' => (string) Str::uuid(),
            'command_type' => 'check_in.provisional',
            'payload' => offlineConflictPayload($branch, $room),
        ]],
    ])->assertOk()->assertJsonPath('data.0.status', 'conflict');

    $this->actingAs($user)->getJson('/api/v1/conflicts')
        ->assertOk()
        ->assertJsonPath('data.0.status', 'conflict')
        ->assertJsonPath('data.0.room_id', $room->id)
        ->assertJsonPath('data.0.command_type', 'check_in.provisional');
});

it('resolves an offline conflict by reassigning the guest to another clean room', function () {
    $branch = Branch::factory()->create();
    $user = $this->makeAdminUser($branch);
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);
    $occupied = Room::factory()->create(['branch_id' => $branch->id, 'room_type_id' => $roomType->id, 'status' => 'occupied']);
    $clean = Room::factory()->create(['branch_id' => $branch->id, 'room_type_id' => $roomType->id, 'status' => 'available', 'condition' => 'clean']);

    $push = $this->actingAs($user)->postJson('/api/v1/sync/push', [
        'device_id' => 'device-a',
        'commands' => [[
            'command_uuid' => (string) Str::uuid(),
            'command_type' => 'check_in.provisional',
            'payload' => offlineConflictPayload($branch, $occupied),
        ]],
    ]);

    $push->assertOk()->assertJsonPath('data.0.status', 'conflict');
    $conflictId = OfflineCommand::query()
        ->where('branch_id', $branch->id)
        ->where('command_uuid', $push->json('data.0.command_uuid'))
        ->firstOrFail()
        ->id;

    $this->actingAs($user)->postJson("/api/v1/conflicts/{$conflictId}/resolve", ['room_id' => $clean->id])
        ->assertOk()
        ->assertJsonPath('data.status', 'applied');

    expect(OfflineProvisionalStay::query()->where('room_id', $clean->id)->exists())->toBeTrue()
        ->and(OfflineProvisionalStay::query()->where('room_id', $occupied->id)->exists())->toBeFalse();
});

it('discards an offline conflict without creating a provisional stay', function () {
    $branch = Branch::factory()->create();
    $user = $this->makeAdminUser($branch);
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);
    $room = Room::factory()->create(['branch_id' => $branch->id, 'room_type_id' => $roomType->id, 'status' => 'occupied']);

    $push = $this->actingAs($user)->postJson('/api/v1/sync/push', [
        'device_id' => 'device-a',
        'commands' => [[
            'command_uuid' => (string) Str::uuid(),
            'command_type' => 'check_in.provisional',
            'payload' => offlineConflictPayload($branch, $room),
        ]],
    ]);

    $conflictId = OfflineCommand::query()
        ->where('branch_id', $branch->id)
        ->where('command_uuid', $push->json('data.0.command_uuid'))
        ->firstOrFail()
        ->id;

    $this->actingAs($user)->postJson("/api/v1/conflicts/{$conflictId}/discard")
        ->assertOk()
        ->assertJsonPath('data.status', 'discarded');

    expect(OfflineProvisionalStay::query()->where('room_id', $room->id)->exists())->toBeFalse();
});

it('renders the offline conflict supervisor queue', function () {
    $this->withoutVite();
    $branch = Branch::factory()->create();
    $user = $this->makeAdminUser($branch);
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);
    $room = Room::factory()->create(['branch_id' => $branch->id, 'room_type_id' => $roomType->id, 'status' => 'occupied']);

    $this->actingAs($user)->postJson('/api/v1/sync/push', [
        'device_id' => 'device-a',
        'commands' => [[
            'command_uuid' => (string) Str::uuid(),
            'command_type' => 'check_in.provisional',
            'payload' => offlineConflictPayload($branch, $room),
        ]],
    ])->assertOk();

    $this->actingAs($user)
        ->get('/offline/conflicts')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('offline/Conflicts')
            ->has('conflicts', 1)
            ->where('branch.id', $branch->id));
});
