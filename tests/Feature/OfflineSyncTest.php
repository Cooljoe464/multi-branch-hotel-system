<?php

use App\Models\Branch;
use App\Models\OfflineCommand;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Subscription;
use App\Services\AvailabilityService;
use App\Services\OfflineSyncService;
use App\Support\BranchTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('AC-038 records a room conflict instead of applying an offline check-in', function () {
    $branch = Branch::factory()->create();
    $user = $this->makeAdminUser($branch);
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);
    $room = Room::factory()->create(['branch_id' => $branch->id, 'room_type_id' => $roomType->id, 'status' => 'occupied']);

    $response = $this->actingAs($user)->postJson('/api/v1/sync/push', [
        'device_id' => 'device-a',
        'commands' => [[
            'command_uuid' => (string) Str::uuid(),
            'command_type' => 'check_in.provisional',
            'payload' => [
                'room_id' => $room->id,
                'room_type_id' => $roomType->id,
                'guest_name' => 'Offline Guest',
                'check_in_date' => now()->toDateString(),
                'check_out_date' => now()->addDay()->toDateString(),
            ],
        ]],
    ]);

    $response->assertOk()->assertJsonPath('data.0.status', 'conflict');
});

it('AC-038 prevents an online check-in from taking a room held by a provisional stay', function () {
    $branch = Branch::factory()->create();
    $user = $this->makeAdminUser($branch);
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);
    $room = Room::factory()->create(['branch_id' => $branch->id, 'room_type_id' => $roomType->id, 'status' => 'available', 'condition' => 'clean']);
    $checkIn = BranchTime::today($branch);
    $checkOut = BranchTime::now($branch)->addDay()->toDateString();

    $this->actingAs($user)->postJson('/api/v1/sync/push', [
        'device_id' => 'offline-device',
        'commands' => [[
            'command_uuid' => (string) Str::uuid(),
            'command_type' => 'check_in.provisional',
            'payload' => [
                'room_id' => $room->id,
                'room_type_id' => $roomType->id,
                'guest_name' => 'Offline Guest',
                'check_in_date' => $checkIn,
                'check_out_date' => $checkOut,
            ],
        ]],
    ])->assertOk()->assertJsonPath('data.0.status', 'applied');

    $reservation = app(AvailabilityService::class)->reserve(
        $branch,
        $roomType,
        $checkIn,
        $checkOut,
        [
            'guest_name' => 'Online Guest',
            'adults' => 1,
            'children' => 0,
            'room_rate' => 10000,
            'total_amount' => 10000,
            'status' => 'confirmed',
            'source' => 'direct',
            'payment_status' => 'pending',
        ],
        null,
        (string) Str::uuid(),
    );

    $this->actingAs($user)
        ->withHeader('X-Idempotency-Key', (string) Str::uuid())
        ->post(route('reservations.check-in', $reservation), ['room_id' => $room->id])
        ->assertSessionHasErrors('room_id');

    expect($reservation->fresh()->status)->toBe('confirmed')
        ->and($reservation->fresh()->room_id)->toBeNull();
});

it('rejects reusing an offline command UUID across properties or devices', function () {
    $branch = Branch::factory()->create();
    $otherBranch = Branch::factory()->create(['tenant_id' => $branch->tenant_id]);
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);
    $room = Room::factory()->create(['branch_id' => $branch->id, 'room_type_id' => $roomType->id, 'status' => 'available', 'condition' => 'clean']);
    $uuid = (string) Str::uuid();
    $payload = [
        'room_id' => $room->id,
        'room_type_id' => $roomType->id,
        'guest_name' => 'Offline Guest',
        'check_in_date' => BranchTime::today($branch),
        'check_out_date' => BranchTime::now($branch)->addDay()->toDateString(),
    ];
    $command = ['command_uuid' => $uuid, 'command_type' => 'check_in.provisional', 'payload' => $payload];
    $service = app(OfflineSyncService::class);

    expect($service->push($branch, 'device-a', [$command])[0]['status'])->toBe('applied');
    $replay = $service->push($otherBranch, 'device-b', [$command])[0];

    expect($replay['status'])->toBe('rejected')
        ->and($replay['reason'])->toBe('Command UUID was already used with a different envelope.');
});

it('AC-039 applies a provisional stay with explicit unpaid and non-fiscal metadata', function () {
    $branch = Branch::factory()->create();
    $user = $this->makeAdminUser($branch);
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);
    $room = Room::factory()->create(['branch_id' => $branch->id, 'room_type_id' => $roomType->id, 'status' => 'available', 'condition' => 'clean']);

    $response = $this->actingAs($user)->postJson('/api/v1/sync/push', [
        'device_id' => 'device-a',
        'commands' => [[
            'command_uuid' => (string) Str::uuid(),
            'command_type' => 'check_in.provisional',
            'payload' => [
                'room_id' => $room->id,
                'room_type_id' => $roomType->id,
                'guest_name' => 'Offline Guest',
                'check_in_date' => now()->toDateString(),
                'check_out_date' => now()->addDay()->toDateString(),
            ],
        ]],
    ]);

    $response->assertOk()->assertJsonPath('data.0.status', 'applied');
    $this->assertDatabaseHas('offline_provisional_stays', [
        'branch_id' => $branch->id,
        'status' => 'provisional',
    ]);
});

it('AC-038 marks an available but unclean room as a conflict for offline check-in', function () {
    $branch = Branch::factory()->create();
    $user = $this->makeAdminUser($branch);
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);
    $room = Room::factory()->create([
        'branch_id' => $branch->id,
        'room_type_id' => $roomType->id,
        'status' => 'available',
        'condition' => 'dirty',
    ]);

    $this->actingAs($user)->postJson('/api/v1/sync/push', [
        'device_id' => 'device-dirty-room',
        'commands' => [[
            'command_uuid' => (string) Str::uuid(),
            'command_type' => 'check_in.provisional',
            'payload' => [
                'room_id' => $room->id,
                'room_type_id' => $roomType->id,
                'guest_name' => 'Offline Guest',
                'check_in_date' => BranchTime::today($branch),
                'check_out_date' => BranchTime::now($branch)->addDay()->toDateString(),
            ],
        ]],
    ])->assertOk()
        ->assertJsonPath('data.0.status', 'conflict')
        ->assertJsonPath('data.0.reason', 'Room assignment conflicts with current inventory.');

    $this->assertDatabaseCount('offline_provisional_stays', 0);
});

it('AC-039 rejects provisional arrivals outside the branch business date', function () {
    $branch = Branch::factory()->create(['timezone' => 'Africa/Lagos']);
    $user = $this->makeAdminUser($branch);
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);
    $room = Room::factory()->create(['branch_id' => $branch->id, 'room_type_id' => $roomType->id, 'status' => 'available', 'condition' => 'clean']);

    $response = $this->actingAs($user)->postJson('/api/v1/sync/push', [
        'device_id' => 'device-date-check',
        'commands' => [[
            'command_uuid' => (string) Str::uuid(),
            'command_type' => 'check_in.provisional',
            'payload' => [
                'room_id' => $room->id,
                'room_type_id' => $roomType->id,
                'guest_name' => 'Offline Guest',
                'check_in_date' => now()->subDay()->toDateString(),
                'check_out_date' => now()->toDateString(),
            ],
        ]],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.0.status', 'rejected')
        ->assertJsonPath('data.0.reason', 'Offline provisional check-in is limited to valid stays arriving today.');
});

it('AC-040 applies a conflict only once with a manager override token', function () {
    $branch = Branch::factory()->create();
    $user = $this->makeAdminUser($branch);
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);
    $room = Room::factory()->create(['branch_id' => $branch->id, 'room_type_id' => $roomType->id, 'status' => 'available', 'condition' => 'clean']);
    $uuid = (string) Str::uuid();

    Reservation::factory()->forBranch($branch->id)->withRoom($room)->create([
        'check_in_date' => now()->toDateString(),
        'check_out_date' => now()->addDay()->toDateString(),
        'status' => 'confirmed',
    ]);

    $payload = [
        'room_id' => $room->id,
        'room_type_id' => $roomType->id,
        'guest_name' => 'Offline Guest',
        'check_in_date' => now()->toDateString(),
        'check_out_date' => now()->addDay()->toDateString(),
    ];

    $first = $this->actingAs($user)->postJson('/api/v1/sync/push', [
        'device_id' => 'device-a',
        'commands' => [['command_uuid' => $uuid, 'command_type' => 'check_in.provisional', 'payload' => $payload]],
    ]);
    $first->assertOk()->assertJsonPath('data.0.status', 'conflict');

    $command = OfflineCommand::where('command_uuid', $uuid)->firstOrFail();
    $override = $this->actingAs($user)->postJson('/api/v1/sync/override-token', ['command_id' => $command->id]);
    $override->assertOk();
    $payload['override_token'] = $override->json('data.token');

    $this->actingAs($user)->postJson('/api/v1/sync/push', [
        'device_id' => 'device-a',
        'commands' => [['command_uuid' => $uuid, 'command_type' => 'check_in.provisional', 'payload' => $payload]],
    ])->assertOk()->assertJsonPath('data.0.status', 'applied');

    // A second replay is idempotent and cannot consume the token again.
    $this->actingAs($user)->postJson('/api/v1/sync/push', [
        'device_id' => 'device-a',
        'commands' => [['command_uuid' => $uuid, 'command_type' => 'check_in.provisional', 'payload' => $payload]],
    ])->assertOk()->assertJsonPath('data.0.status', 'applied');
});

it('AC-040 rejects an expired manager override token', function () {
    $branch = Branch::factory()->create();
    $user = $this->makeAdminUser($branch);
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);
    $room = Room::factory()->create(['branch_id' => $branch->id, 'room_type_id' => $roomType->id, 'status' => 'available', 'condition' => 'clean']);
    Reservation::factory()->forBranch($branch->id)->withRoom($room)->create([
        'check_in_date' => now()->toDateString(),
        'check_out_date' => now()->addDay()->toDateString(),
        'status' => 'confirmed',
    ]);
    $uuid = (string) Str::uuid();
    $payload = [
        'room_id' => $room->id,
        'room_type_id' => $roomType->id,
        'guest_name' => 'Offline Guest',
        'check_in_date' => now()->toDateString(),
        'check_out_date' => now()->addDay()->toDateString(),
    ];

    $this->actingAs($user)->postJson('/api/v1/sync/push', [
        'device_id' => 'device-expired-override',
        'commands' => [['command_uuid' => $uuid, 'command_type' => 'check_in.provisional', 'payload' => $payload]],
    ])->assertOk()->assertJsonPath('data.0.status', 'conflict');

    $command = OfflineCommand::where('command_uuid', $uuid)->firstOrFail();
    $override = $this->actingAs($user)->postJson('/api/v1/sync/override-token', ['command_id' => $command->id]);
    $command->update(['override_expires_at' => now()->subSecond()]);
    $payload['override_token'] = $override->json('data.token');

    $this->actingAs($user)->postJson('/api/v1/sync/push', [
        'device_id' => 'device-expired-override',
        'commands' => [['command_uuid' => $uuid, 'command_type' => 'check_in.provisional', 'payload' => $payload]],
    ])->assertOk()
        ->assertJsonPath('data.0.status', 'conflict')
        ->assertJsonPath('data.0.reason', 'Room assignment conflicts with current inventory.');
});

it('AC-042 rejects an offline check-in queued before a subscription is restricted', function () {
    $branch = Branch::factory()->create();
    $user = $this->makeAdminUser($branch);
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);
    $room = Room::factory()->create(['branch_id' => $branch->id, 'room_type_id' => $roomType->id, 'status' => 'available', 'condition' => 'clean']);
    $plan = Plan::factory()->create();
    PlanEntitlement::create([
        'plan_id' => $plan->id,
        'entitlement_key' => 'pms.reservations',
        'value_type' => PlanEntitlement::TYPE_BOOLEAN,
        'value' => 'true',
    ]);
    Subscription::factory()->for($branch->tenant)->for($plan)->restricted()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/sync/push', [
        'device_id' => 'device-restricted',
        'commands' => [[
            'command_uuid' => (string) Str::uuid(),
            'command_type' => 'check_in.provisional',
            'payload' => [
                'room_id' => $room->id,
                'room_type_id' => $roomType->id,
                'guest_name' => 'Queued Guest',
                'check_in_date' => now()->toDateString(),
                'check_out_date' => now()->addDay()->toDateString(),
            ],
        ]],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.0.status', 'rejected')
        ->assertJsonPath('data.0.reason', 'The pms.reservations entitlement is not active for this property.');
});
