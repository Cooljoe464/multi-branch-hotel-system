<?php

use App\Http\Controllers\GuestPortalController;
use App\Models\Branch;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['timezone' => 'Africa/Lagos']);
    $this->user = $this->makeAdminUser($this->branch);
});

it('rejects a guest check-in link without a valid signature', function () {
    $reservation = Reservation::factory()->forBranch($this->branch->id)->create();

    $this->get("/guest/checkin/{$reservation->confirmation_number}")
        ->assertForbidden();

    $this->postJson("/guest/checkin/{$reservation->confirmation_number}/submit", [
        'eta' => now()->toDateString(),
    ])->assertForbidden();
});

it('renders the guest check-in form for a reservation with a valid signed token', function () {
    $this->withoutVite();
    $reservation = Reservation::factory()->forBranch($this->branch->id)->create([
        'status' => 'confirmed',
        'check_in_date' => now()->toDateString(),
        'check_out_date' => now()->addDay()->toDateString(),
    ]);

    $token = app(GuestPortalController::class)->checkinToken($reservation);

    $this->get("/guest/checkin/{$reservation->confirmation_number}?token={$token}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('guest/Checkin')
            ->where('reservation.confirmation_number', $reservation->confirmation_number));

    $this->postJson("/guest/checkin/{$reservation->confirmation_number}/submit?token={$token}", [
        'eta' => now()->toDateString(),
    ])->assertOk();
});

it('rejects a check-in token signed for a different reservation', function () {
    $reservation = Reservation::factory()->forBranch($this->branch->id)->create();

    $token = app(GuestPortalController::class)->checkinToken($reservation);

    $other = Reservation::factory()->forBranch($this->branch->id)->create();

    $this->get("/guest/checkin/{$other->confirmation_number}?token={$token}")
        ->assertForbidden();
});
