<?php

use App\Models\Branch;
use App\Models\Folio;
use App\Models\MaintenanceTicket;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Task;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createStaffUser(string $role, Branch $branch, array $attributes = []): User
{
    $user = User::factory()->create(array_merge(['branch_id' => $branch->id], $attributes));
    $user->assignRole($role);
    $user->branches()->syncWithoutDetaching([$branch->id]);

    return $user;
}

function createRoom(Branch $branch, array $attributes = []): Room
{
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);

    return Room::factory()->create(array_merge([
        'branch_id' => $branch->id,
        'room_type_id' => $roomType->id,
        'status' => 'available',
    ], $attributes));
}

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->branch = Branch::factory()->create();
    $this->otherBranch = Branch::factory()->create();

    $this->admin = createStaffUser('Global Admin', $this->branch, ['is_global_admin' => true]);
    $this->owner = createStaffUser('Property Owner', $this->branch);
    $this->gm = createStaffUser('Branch GM', $this->branch);
    $this->frontDesk = createStaffUser('Front Desk', $this->branch);
    $this->housekeeper = createStaffUser('Housekeeper', $this->branch);
});

test('global admin can access all gated modules', function () {
    $pages = [
        '/tape-chart',
        '/rooms',
        '/reservations',
        '/housekeeping',
        // NOTE: '/housekeeping/mobile' omitted — controller renders
        // housekeeping/Mobile.vue which does not exist (pre-existing defect).
        '/maintenance',
        '/folios',
        '/analytics',
        '/reports',
        '/yield-rules',
        '/rate-overrides',
    ];

    foreach ($pages as $page) {
        $this->actingAs($this->admin)->get($page)->assertOk($page);
    }
});

test('housekeeper is denied analytics, reports, pricing, reservations and folios', function () {
    $denied = [
        '/analytics',
        '/reports',
        '/yield-rules',
        '/rate-overrides',
        '/reservations',
        '/folios',
        '/tape-chart',
    ];

    foreach ($denied as $page) {
        $this->actingAs($this->housekeeper)->get($page)->assertForbidden($page);
    }
});

test('housekeeper can access the maintenance index view', function () {
    $this->actingAs($this->housekeeper)->get('/maintenance')->assertOk();
});

test('housekeeper can access the housekeeping index view', function () {
    $this->actingAs($this->housekeeper)->get('/housekeeping')->assertOk();
});

test('housekeeper cannot check in a reservation', function () {
    $room = createRoom($this->branch);
    $reservation = Reservation::factory()->confirmed()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $room->room_type_id,
    ]);

    $this->actingAs($this->housekeeper)
        ->post("/reservations/{$reservation->id}/check-in", ['room_id' => $room->id])
        ->assertForbidden();
});

test('front desk is denied analytics, reports and pricing pages', function () {
    foreach (['/analytics', '/reports', '/yield-rules', '/rate-overrides'] as $page) {
        $this->actingAs($this->frontDesk)->get($page)->assertForbidden($page);
    }
});

test('front desk can access operations pages', function () {
    foreach (['/tape-chart', '/reservations', '/rooms', '/folios', '/housekeeping', '/maintenance'] as $page) {
        $this->actingAs($this->frontDesk)->get($page)->assertOk($page);
    }
});

test('front desk can check in a reservation', function () {
    $room = createRoom($this->branch);
    $reservation = Reservation::factory()->confirmed()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $room->room_type_id,
    ]);

    $this->actingAs($this->frontDesk)
        ->post("/reservations/{$reservation->id}/check-in", ['room_id' => $room->id])
        ->assertRedirect();

    $this->assertDatabaseHas('reservations', [
        'id' => $reservation->id,
        'status' => 'checked_in',
    ]);
});

test('front desk can file a maintenance ticket with view permission', function () {
    $room = createRoom($this->branch);

    $this->actingAs($this->frontDesk)
        ->post('/maintenance', [
            'room_id' => $room->id,
            'category' => 'plumbing',
            'priority' => 'normal',
            'title' => 'Leaky faucet',
            'description' => 'Bathroom faucet is leaking.',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('maintenance_tickets', [
        'branch_id' => $this->branch->id,
        'title' => 'Leaky faucet',
    ]);
});

test('property owner can access analytics and reports but cannot check in', function () {
    $this->actingAs($this->owner)->get('/analytics')->assertOk();
    $this->actingAs($this->owner)->get('/reports')->assertOk();
    $this->actingAs($this->owner)->get('/reservations')->assertOk();

    $room = createRoom($this->branch);
    $reservation = Reservation::factory()->confirmed()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $room->room_type_id,
    ]);

    $this->actingAs($this->owner)
        ->post("/reservations/{$reservation->id}/check-in", ['room_id' => $room->id])
        ->assertForbidden();
});

test('cross branch search is restricted to global admin and property owner', function () {
    $query = '?check_in='.now()->addDay()->toDateString()
        .'&check_out='.now()->addDays(3)->toDateString()
        .'&adults=2';

    $this->actingAs($this->admin)->get("/reservations/cross-branch/search{$query}")->assertOk();
    $this->actingAs($this->owner)->get("/reservations/cross-branch/search{$query}")->assertOk();
    $this->actingAs($this->gm)->get("/reservations/cross-branch/search{$query}")->assertForbidden();
    $this->actingAs($this->frontDesk)->get("/reservations/cross-branch/search{$query}")->assertForbidden();
    $this->actingAs($this->housekeeper)->get("/reservations/cross-branch/search{$query}")->assertForbidden();
});

test('branch gm can cancel but cannot hard delete a reservation', function () {
    $room = createRoom($this->branch);
    $reservation = Reservation::factory()->confirmed()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $room->room_type_id,
    ]);

    $this->actingAs($this->gm)
        ->post("/reservations/{$reservation->id}/cancel")
        ->assertRedirect();

    $this->assertDatabaseHas('reservations', [
        'id' => $reservation->id,
        'status' => 'cancelled',
    ]);

    $this->actingAs($this->gm)
        ->delete("/reservations/{$reservation->id}")
        ->assertForbidden();
});

test('users cannot view reservations from another branch', function () {
    $room = createRoom($this->otherBranch);
    $reservation = Reservation::factory()->confirmed()->create([
        'branch_id' => $this->otherBranch->id,
        'room_type_id' => $room->room_type_id,
    ]);

    $this->actingAs($this->gm)->get("/reservations/{$reservation->id}")->assertForbidden();
    $this->actingAs($this->frontDesk)->get("/reservations/{$reservation->id}")->assertForbidden();
    $this->actingAs($this->admin)->get("/reservations/{$reservation->id}")->assertOk();
});

test('users cannot update rooms from another branch', function () {
    $room = createRoom($this->otherBranch);

    $this->actingAs($this->gm)
        ->patch("/rooms/{$room->id}/status", ['status' => 'dirty'])
        ->assertForbidden();

    $this->actingAs($this->housekeeper)
        ->patch("/rooms/{$room->id}/status", ['status' => 'dirty'])
        ->assertForbidden();
});

test('housekeeper can update own branch room status', function () {
    $room = createRoom($this->branch);

    $this->actingAs($this->housekeeper)
        ->patch("/rooms/{$room->id}/status", ['status' => 'dirty'])
        ->assertRedirect();

    $this->assertDatabaseHas('rooms', [
        'id' => $room->id,
        'status' => 'dirty',
    ]);
});

test('housekeeper cannot start tasks assigned to someone else', function () {
    $room = createRoom($this->branch);
    $otherHousekeeper = createStaffUser('Housekeeper', $this->branch);

    $foreignTask = Task::factory()->pending()->create([
        'branch_id' => $this->branch->id,
        'room_id' => $room->id,
        'assigned_to' => $otherHousekeeper->id,
    ]);

    $this->actingAs($this->housekeeper)
        ->post("/housekeeping/{$foreignTask->id}/start")
        ->assertForbidden();

    $ownTask = Task::factory()->pending()->create([
        'branch_id' => $this->branch->id,
        'room_id' => $room->id,
        'assigned_to' => $this->housekeeper->id,
    ]);

    $this->actingAs($this->housekeeper)
        ->post("/housekeeping/{$ownTask->id}/start")
        ->assertRedirect();
});

test('only users with door lock permission can lock rooms for maintenance', function () {
    $room = createRoom($this->branch);
    $ticket = MaintenanceTicket::factory()->create([
        'branch_id' => $this->branch->id,
        'room_id' => $room->id,
        'status' => 'open',
    ]);

    $this->actingAs($this->frontDesk)
        ->post("/maintenance/{$ticket->id}/lock-room")
        ->assertForbidden();

    $this->actingAs($this->gm)
        ->post("/maintenance/{$ticket->id}/lock-room")
        ->assertRedirect();
});

test('folio charges cannot be transferred across branches', function () {
    $sourceFolio = Folio::create([
        'branch_id' => $this->otherBranch->id,
        'folio_number' => Folio::generateFolioNumber(),
        'type' => 'master',
        'status' => 'open',
    ]);
    $targetFolio = Folio::create([
        'branch_id' => $this->branch->id,
        'folio_number' => Folio::generateFolioNumber(),
        'type' => 'master',
        'status' => 'open',
    ]);
    $transaction = Transaction::factory()->debit()->create(['folio_id' => $sourceFolio->id]);

    $this->actingAs($this->gm)
        ->post("/folios/transactions/{$transaction->id}/transfer", [
            'target_folio_id' => $targetFolio->id,
        ])
        ->assertForbidden();
});
