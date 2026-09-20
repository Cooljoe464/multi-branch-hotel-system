<?php

use App\Models\Branch;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Foundation\Testing\DatabaseMigrations;

uses(DatabaseMigrations::class);

it('loads the booking engine page', function () {
    Branch::factory()->create();

    visit('/book')
        ->assertSee('Book Your Stay')
        ->assertSee('Select Property & Dates')
        ->assertPathIs('/book');
});

it('searches for available rooms', function () {
    $branch = Branch::factory()->create();
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);
    Room::factory()->count(2)->create([
        'branch_id' => $branch->id,
        'room_type_id' => $roomType->id,
        'status' => 'available',
    ]);

    $branchLabel = "{$branch->name} ({$branch->city}, {$branch->country})";

    $page = visit('/book')
        ->click('Select a property')
        ->wait(1);

    $page->script(sprintf(
        'document.querySelectorAll("[data-slot=\\"select-item\\"]").forEach(function(el) { if (el.textContent.trim() === %s) { el.dispatchEvent(new PointerEvent("pointerdown", {bubbles:true, cancelable:true})); el.dispatchEvent(new PointerEvent("pointerup", {bubbles:true, cancelable:true})); el.click(); } })',
        json_encode($branchLabel)
    ));

    $checkIn = now()->addDays(7)->format('Y-m-d');
    $checkOut = now()->addDays(10)->format('Y-m-d');

    $page->script(sprintf(
        'document.dispatchEvent(new CustomEvent("setdate", { detail: { id: "check_in", date: %s } })); document.dispatchEvent(new CustomEvent("setdate", { detail: { id: "check_out", date: %s } }));',
        json_encode($checkIn),
        json_encode($checkOut)
    ));

    $page->wait(1)
        ->click('Search Availability')
        ->wait(3)
        ->assertSee($roomType->name);
});

it('creates a reservation through the booking engine', function () {
    $branch = Branch::factory()->create();
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);
    Room::factory()->create([
        'branch_id' => $branch->id,
        'room_type_id' => $roomType->id,
        'status' => 'available',
    ]);

    $branchLabel = "{$branch->name} ({$branch->city}, {$branch->country})";

    $page = visit('/book')
        ->click('Select a property')
        ->wait(1);

    $page->script(sprintf(
        'document.querySelectorAll("[data-slot=\\"select-item\\"]").forEach(function(el) { if (el.textContent.trim() === %s) { el.dispatchEvent(new PointerEvent("pointerdown", {bubbles:true, cancelable:true})); el.dispatchEvent(new PointerEvent("pointerup", {bubbles:true, cancelable:true})); el.click(); } })',
        json_encode($branchLabel)
    ));

    $checkIn = now()->addDays(7)->format('Y-m-d');
    $checkOut = now()->addDays(10)->format('Y-m-d');

    $page->script(sprintf(
        'document.dispatchEvent(new CustomEvent("setdate", { detail: { id: "check_in", date: %s } })); document.dispatchEvent(new CustomEvent("setdate", { detail: { id: "check_out", date: %s } }));',
        json_encode($checkIn),
        json_encode($checkOut)
    ));

    $page->wait(1)
        ->click('Search Availability')
        ->wait(3)
        ->click($roomType->name)
        ->wait(1)
        ->type('first_name', 'Jane')
        ->type('last_name', 'Smith')
        ->type('email', 'jane@example.com')
        ->type('phone', '555-0199')
        ->click('Review Booking')
        ->wait(2);

    $page->wait(2)
        ->check('input[name="terms"]')
        ->wait(1)
        ->click('Complete Booking')
        ->wait(5)
        ->assertSee('Booking Confirmed');

    $this->assertDatabaseHas('reservations', [
        'branch_id' => $branch->id,
        'guest_email' => 'jane@example.com',
        'status' => 'confirmed',
    ]);

    $this->assertDatabaseHas('guests', [
        'email' => 'jane@example.com',
    ]);
});

it('views guest folio after booking', function () {
    $branch = Branch::factory()->create();
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);
    $reservation = Reservation::factory()->create([
        'branch_id' => $branch->id,
        'room_type_id' => $roomType->id,
        'guest_email' => 'folio-test@example.com',
    ]);

    visit("/guest/folio/{$reservation->confirmation_number}")
        ->wait(3)
        ->assertSee($reservation->confirmation_number)
        ->assertSee($branch->name)
        ->assertSee($branch->city);
});

it('performs guest lookup with valid credentials', function () {
    $branch = Branch::factory()->create();
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);
    $reservation = Reservation::factory()->create([
        'branch_id' => $branch->id,
        'room_type_id' => $roomType->id,
        'guest_email' => 'lookup-test@example.com',
    ]);

    visit('/guest/folio/'.$reservation->confirmation_number)
        ->wait(3)
        ->assertSee($reservation->confirmation_number);
});

it('fails guest lookup with wrong email', function () {
    $branch = Branch::factory()->create();
    $roomType = RoomType::factory()->create(['branch_id' => $branch->id]);
    $reservation = Reservation::factory()->create([
        'branch_id' => $branch->id,
        'room_type_id' => $roomType->id,
        'guest_email' => 'correct@example.com',
    ]);

    $this->post('/guest/lookup', [
        'email' => 'wrong@example.com',
        'confirmation' => $reservation->confirmation_number,
    ])->assertSessionHasErrors('email');
});
