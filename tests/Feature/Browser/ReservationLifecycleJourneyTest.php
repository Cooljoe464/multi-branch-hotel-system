<?php

use App\Models\Branch;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;

uses(DatabaseMigrations::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->branch = Branch::factory()->create();
    $this->roomType = RoomType::factory()->create(['branch_id' => $this->branch->id, 'base_rate' => 20000]);
    $this->room = Room::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'number' => '101',
        'status' => 'available',
    ]);

    $this->user = User::factory()->create([
        'branch_id' => $this->branch->id,
        'email' => 'frontdesk@test.com',
        'password' => 'password',
    ]);
    $this->user->assignRole('Branch GM');
    $this->user->branches()->syncWithoutDetaching([$this->branch->id]);
});

it('creates a reservation via the staff form', function () {
    $checkIn = now()->addDays(7)->format('Y-m-d');
    $checkOut = now()->addDays(10)->format('Y-m-d');

    $page = visit('/login')
        ->type('email', 'frontdesk@test.com')
        ->type('password', 'password')
        ->press('Log in')
        ->wait(3)
        ->assertPathIs('/dashboard');

    $page->click('Reservations')->wait(2);

    $page->click('New Reservation')->wait(2)
        ->assertSee('New Reservation')
        ->assertSee('Guest Information');

    $page->type('guest_name', 'Jane Smith')
        ->type('#guest_phone', '555-0199')
        ->wait(1);

    $page->click('Select room type')->wait(1);

    $page->script(sprintf(
        'document.querySelectorAll("[data-slot=\\"select-item\\"]").forEach(function(el) { if (el.textContent.includes(%s)) { el.dispatchEvent(new PointerEvent("pointerdown", {bubbles:true, cancelable:true})); el.dispatchEvent(new PointerEvent("pointerup", {bubbles:true, cancelable:true})); el.click(); } })',
        json_encode($this->roomType->name)
    ));

    $page->wait(1)->script(sprintf(
        'document.dispatchEvent(new CustomEvent("setdate", { detail: { id: "check_in_date", date: %s } })); document.dispatchEvent(new CustomEvent("setdate", { detail: { id: "check_out_date", date: %s } }));',
        json_encode($checkIn),
        json_encode($checkOut)
    ));

    $page->wait(1)
        ->click('Create Reservation')
        ->wait(3);

    $this->assertDatabaseHas('reservations', [
        'branch_id' => $this->branch->id,
        'guest_name' => 'Jane Smith',
        'status' => 'confirmed',
    ]);
});

it('navigates from reservation list to detail page', function () {
    $reservation = Reservation::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'guest_name' => 'John Doe',
        'status' => 'confirmed',
    ]);

    $page = visit('/login')
        ->type('email', 'frontdesk@test.com')
        ->type('password', 'password')
        ->press('Log in')
        ->wait(3)
        ->click('Reservations')
        ->wait(2)
        ->assertSee($reservation->confirmation_number)
        ->assertSee('John Doe');

    $page->click('View')->wait(3)
        ->assertSee($reservation->confirmation_number)
        ->assertSee('John Doe')
        ->assertSee('Guest Information')
        ->assertSee('Stay Details');
});

it('checks in a reservation with room assignment via HTTP', function () {
    $reservation = Reservation::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'room_id' => null,
        'guest_name' => 'Alice Brown',
        'status' => 'confirmed',
    ]);

    visit('/login')
        ->type('email', 'frontdesk@test.com')
        ->type('password', 'password')
        ->press('Log in')
        ->wait(3)
        ->click('Reservations')
        ->wait(2)
        ->click('View')
        ->wait(3)
        ->assertSee('Check In')
        ->assertSee('Alice Brown');

    $this->actingAs($this->user)
        ->withSession(['branch_id' => $this->branch->id])
        ->post("/reservations/{$reservation->id}/check-in", ['room_id' => $this->room->id])
        ->assertRedirect();

    $this->assertDatabaseHas('reservations', [
        'id' => $reservation->id,
        'status' => 'checked_in',
    ]);

    $this->assertDatabaseHas('rooms', [
        'id' => $this->room->id,
        'status' => 'occupied',
    ]);
});

it('shows check out button only when reservation is checked in', function () {
    $reservation = Reservation::factory()->checkedIn()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'room_id' => $this->room->id,
        'guest_name' => 'Bob Wilson',
    ]);

    visit('/login')
        ->type('email', 'frontdesk@test.com')
        ->type('password', 'password')
        ->press('Log in')
        ->wait(3)
        ->click('Reservations')
        ->wait(2)
        ->click('View')
        ->wait(3)
        ->assertSee('Check Out')
        ->assertSee('checked in');
});

it('completes full reservation lifecycle from create to check out', function () {
    $checkIn = now()->addDays(3)->format('Y-m-d');
    $checkOut = now()->addDays(5)->format('Y-m-d');

    // Step 1: Login and create reservation via form
    $page = visit('/login')
        ->type('email', 'frontdesk@test.com')
        ->type('password', 'password')
        ->press('Log in')
        ->wait(3)
        ->click('Reservations')
        ->wait(2)
        ->click('New Reservation')
        ->wait(2)
        ->type('guest_name', 'E2E Lifecycle Test')
        ->wait(1);

    $page->click('Select room type')->wait(1);

    $page->script(sprintf(
        'document.querySelectorAll("[data-slot=\\"select-item\\"]").forEach(function(el) { if (el.textContent.includes(%s)) { el.dispatchEvent(new PointerEvent("pointerdown", {bubbles:true, cancelable:true})); el.dispatchEvent(new PointerEvent("pointerup", {bubbles:true, cancelable:true})); el.click(); } })',
        json_encode($this->roomType->name)
    ));

    $page->wait(1)->script(sprintf(
        'document.dispatchEvent(new CustomEvent("setdate", { detail: { id: "check_in_date", date: %s } })); document.dispatchEvent(new CustomEvent("setdate", { detail: { id: "check_out_date", date: %s } }));',
        json_encode($checkIn),
        json_encode($checkOut)
    ));

    $page->wait(1)->click('Create Reservation')->wait(3);

    $reservation = Reservation::where('guest_name', 'E2E Lifecycle Test')->first();
    expect($reservation)->not->toBeNull();
    expect($reservation->status)->toBe('confirmed');

    // Step 2: Navigate to detail page and check in via HTTP
    visit("/reservations/{$reservation->id}")->wait(3)
        ->assertSee('Check In')
        ->assertSee('E2E Lifecycle Test');

    $this->actingAs($this->user)
        ->withSession(['branch_id' => $this->branch->id])
        ->post("/reservations/{$reservation->id}/check-in", ['room_id' => $this->room->id])
        ->assertRedirect();

    $reservation->refresh();
    expect($reservation->status)->toBe('checked_in');

    // Step 3: Check out via HTTP
    visit("/reservations/{$reservation->id}")->wait(3)
        ->assertSee('Check Out');

    $this->actingAs($this->user)
        ->withSession(['branch_id' => $this->branch->id])
        ->post("/reservations/{$reservation->id}/check-out")
        ->assertRedirect();

    $reservation->refresh();
    expect($reservation->status)->toBe('checked_out');

    $this->assertDatabaseHas('rooms', [
        'id' => $this->room->id,
        'status' => 'dirty',
    ]);
});

it('cancels a confirmed reservation and releases room', function () {
    $reservation = Reservation::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'room_id' => $this->room->id,
        'guest_name' => 'Cancel Test',
        'status' => 'reserved',
    ]);

    $this->room->update(['status' => 'reserved']);

    $page = visit('/login')
        ->type('email', 'frontdesk@test.com')
        ->type('password', 'password')
        ->press('Log in')
        ->wait(3)
        ->click('Reservations')
        ->wait(2)
        ->click('View')
        ->wait(3)
        ->assertSee('Cancel')
        ->assertSee('Cancel Test');

    $this->actingAs($this->user)
        ->withSession(['branch_id' => $this->branch->id])
        ->post("/reservations/{$reservation->id}/cancel")
        ->assertRedirect();

    $reservation->refresh();
    expect($reservation->status)->toBe('cancelled');

    $this->assertDatabaseHas('rooms', [
        'id' => $this->room->id,
        'status' => 'available',
    ]);
});
