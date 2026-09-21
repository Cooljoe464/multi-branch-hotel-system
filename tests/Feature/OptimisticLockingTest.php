<?php

use App\Exceptions\StaleModelException;
use App\Models\Branch;
use App\Models\KotItem;
use App\Models\PosCharge;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\FolioService;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
});

it('rejects a stale reservation update with 409 JSON', function () {
    $reservation = Reservation::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
    ]);

    // Someone else edits first: version moves 1 -> 2.
    $reservation->saveWithVersion(['guest_notes' => 'first writer'], 1);

    $response = $this->actingAs($this->user)->putJson("/reservations/{$reservation->id}", [
        'guest_notes' => 'stale writer',
        'version' => 1,
    ]);

    $response->assertConflict()->assertJsonPath('code', 'STALE_VERSION');

    expect($reservation->fresh()->guest_notes)->toBe('first writer');
});

it('accepts a fresh reservation update and bumps the version', function () {
    $reservation = Reservation::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
    ]);

    $this->actingAs($this->user)->put("/reservations/{$reservation->id}", [
        'guest_notes' => 'fresh writer',
        'version' => 1,
    ])->assertRedirect();

    $fresh = $reservation->fresh();
    expect($fresh->guest_notes)->toBe('fresh writer')->and($fresh->version)->toBe(2);
});

it('sends a stale web update back with a version error', function () {
    $reservation = Reservation::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
    ]);
    $reservation->saveWithVersion(['guest_notes' => 'first writer'], 1);

    $this->actingAs($this->user)->put("/reservations/{$reservation->id}", [
        'guest_notes' => 'stale writer',
        'version' => 1,
    ])->assertRedirect()->assertSessionHasErrors('version');
});

it('allows only one of two concurrent folio posts with the same version', function () {
    $folioService = new FolioService;
    $folio = $folioService->createStaffFolio($this->branch->id, 'Version Guest');

    expect($folio->version)->toBe(1);

    $folioService->postManualCharge($folio, 'misc', 'first post', 1000, null, $this->user->id, 1);

    expect($folio->fresh()->version)->toBe(2);

    try {
        $folioService->postManualCharge($folio, 'misc', 'stale post', 1000, null, $this->user->id, 1);
        $this->fail('Expected a StaleModelException for the second post.');
    } catch (StaleModelException) {
    }

    expect($folio->transactions()->where('is_voided', false)->count())->toBe(1);
});

it('rejects a stale room status change', function () {
    $this->room->saveWithVersion(['notes' => 'first writer'], 1);

    $response = $this->actingAs($this->user)->patchJson("/rooms/{$this->room->id}/status", [
        'status' => 'dirty',
        'version' => 1,
    ]);

    $response->assertConflict();
});

it('throws StaleModelException for a stale KOT update', function () {
    $reservation = Reservation::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
    ]);
    $folio = (new FolioService)->createStaffFolio($this->branch->id, 'KOT Guest');

    $charge = PosCharge::factory()->create([
        'branch_id' => $this->branch->id,
        'reservation_id' => $reservation->id,
        'folio_id' => $folio->id,
    ]);

    $item = KotItem::factory()->create([
        'pos_charge_id' => $charge->id,
        'branch_id' => $this->branch->id,
    ]);

    $item->saveWithVersion(['status' => 'preparing'], 1);

    expect(fn () => $item->saveWithVersion(['status' => 'served'], 1))
        ->toThrow(StaleModelException::class);
});
