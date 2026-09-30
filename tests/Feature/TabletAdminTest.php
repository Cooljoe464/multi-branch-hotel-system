<?php

use App\Models\Branch;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\TabletSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['timezone' => 'Africa/Lagos']);
    $this->user = $this->makeAdminUser($this->branch);
});

it('renders the tablet index', function () {
    $this->actingAs($this->user)
        ->get("/branches/{$this->branch->id}/tablets")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('rooms/Tablets'));
});

it('wipes a session from the index flow', function () {
    $roomType = RoomType::factory()->create(['branch_id' => $this->branch->id, 'base_rate' => 10000]);
    $room = Room::factory()->create([
        'branch_id' => $this->branch->id, 'room_type_id' => $roomType->id,
        'number' => '101', 'status' => 'occupied', 'is_active' => true,
    ]);
    $reservation = Reservation::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $roomType->id,
        'room_id' => $room->id,
        'check_in_date' => Carbon::today()->toDateString(),
        'check_out_date' => Carbon::today()->addDays(2)->toDateString(),
        'status' => 'checked_in',
        'room_rate' => 10000,
        'total_amount' => 20000,
    ]);

    $session = TabletSession::create([
        'branch_id' => $this->branch->id,
        'room_id' => $room->id,
        'reservation_id' => $reservation->id,
        'confirmation_number' => $reservation->confirmation_number ?? (string) Str::uuid(),
        'device_id' => 'tablet-1',
    ]);

    $this->actingAs($this->user)->post('/tablet/wipe', ['tablet_session_id' => $session->id])->assertRedirect();

    expect($session->fresh()?->wiped_at)->not->toBeNull();
});
