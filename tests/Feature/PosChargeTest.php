<?php

use App\Models\Branch;
use App\Models\KitchenStation;
use App\Models\MenuItem;
use App\Models\MenuItemStation;
use App\Models\Outlet;
use App\Models\PosCharge;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create();
    $this->roomType = RoomType::factory()->create(['branch_id' => $this->branch->id]);
    $this->room = Room::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'status' => 'occupied',
    ]);
    $this->reservation = Reservation::factory()->checkedIn()->create([
        'branch_id' => $this->branch->id,
        'room_id' => $this->room->id,
        'room_type_id' => $this->roomType->id,
    ]);
    $this->user = $this->makeAdminUser($this->branch);
});

it('can post a POS charge to a checked-in reservation', function () {
    $token = $this->user->createToken('pos-token');
    $this->app['auth']->guard('sanctum')->setUser($this->user);

    $response = $this->withToken($token->plainTextToken)
        ->postJson('/api/pos/charge', [
            'reservation_id' => $this->reservation->id,
            'outlet' => 'restaurant',
            'items' => [
                ['name' => 'Grilled Chicken', 'qty' => 2, 'unit_price' => 2500],
            ],
        ]);

    $response->assertStatus(201)
        ->assertJson(['message' => 'Charge posted successfully.']);

    $this->assertDatabaseHas('pos_charges', [
        'reservation_id' => $this->reservation->id,
        'outlet' => 'restaurant',
        'status' => 'posted',
    ]);

    $this->assertDatabaseHas('transactions', [
        'category' => 'restaurant',
    ]);

    $this->assertDatabaseHas('kot_items', [
        'item_name' => 'Grilled Chicken',
        'quantity' => 2,
    ]);
});

it('cannot post charge to non-checked-in reservation', function () {
    $pendingReservation = Reservation::factory()->pending()->create([
        'branch_id' => $this->branch->id,
    ]);

    $token = $this->user->createToken('pos-token');

    $response = $this->withToken($token->plainTextToken)
        ->postJson('/api/pos/charge', [
            'reservation_id' => $pendingReservation->id,
            'outlet' => 'restaurant',
            'items' => [
                ['name' => 'Pizza', 'qty' => 1, 'unit_price' => 1500],
            ],
        ]);

    $response->assertStatus(422)
        ->assertJson(['message' => 'Reservation must be checked in to post charges.']);
});

it('requires authentication for POS endpoint', function () {
    $response = $this->postJson('/api/pos/charge', [
        'reservation_id' => $this->reservation->id,
        'outlet' => 'restaurant',
        'items' => [['name' => 'Pizza', 'qty' => 1, 'unit_price' => 1500]],
    ]);

    $response->assertStatus(401);
});

it('validates POS charge required fields', function () {
    $token = $this->user->createToken('pos-token');

    $response = $this->withToken($token->plainTextToken)
        ->postJson('/api/pos/charge', [
            'reservation_id' => $this->reservation->id,
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['outlet', 'items']);
});

it('validates POS charge item fields', function () {
    $token = $this->user->createToken('pos-token');

    $response = $this->withToken($token->plainTextToken)
        ->postJson('/api/pos/charge', [
            'reservation_id' => $this->reservation->id,
            'outlet' => 'restaurant',
            'items' => [['name' => 'Pizza']],
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['items.0.qty', 'items.0.unit_price']);
});

it('calculates tax on POS charge', function () {
    $this->branch->update(['tax_rate' => 7.5]);

    $token = $this->user->createToken('pos-token');

    $response = $this->withToken($token->plainTextToken)
        ->postJson('/api/pos/charge', [
            'reservation_id' => $this->reservation->id,
            'outlet' => 'restaurant',
            'items' => [
                ['name' => 'Pizza', 'qty' => 1, 'unit_price' => 2000],
            ],
        ]);

    $response->assertStatus(201);

    $posCharge = PosCharge::where('reservation_id', $this->reservation->id)->first();
    expect($posCharge->subtotal)->toBe(2000)
        ->and($posCharge->tax_amount)->toBeGreaterThan(0)
        ->and($posCharge->total)->toBeGreaterThan(2000);
});

it('creates KOT items for each POS item', function () {
    $token = $this->user->createToken('pos-token');

    $response = $this->withToken($token->plainTextToken)
        ->postJson('/api/pos/charge', [
            'reservation_id' => $this->reservation->id,
            'outlet' => 'restaurant',
            'items' => [
                ['name' => 'Pizza', 'qty' => 1, 'unit_price' => 1500],
                ['name' => 'Salad', 'qty' => 2, 'unit_price' => 800],
            ],
        ]);

    $response->assertStatus(201);

    $posCharge = PosCharge::where('reservation_id', $this->reservation->id)->first();
    $this->assertDatabaseHas('kot_items', [
        'pos_charge_id' => $posCharge->id,
        'item_name' => 'Pizza',
        'quantity' => 1,
    ]);
    $this->assertDatabaseHas('kot_items', [
        'pos_charge_id' => $posCharge->id,
        'item_name' => 'Salad',
        'quantity' => 2,
    ]);
});

it('renders terminal page with checked-in reservations and outlet menu items', function () {
    $outlet = Outlet::factory()->forBranch($this->branch->id)->restaurant()->create();
    $station = KitchenStation::factory()->create([
        'branch_id' => $this->branch->id,
        'outlet_id' => $outlet->id,
    ]);
    $menuItem = MenuItem::factory()->food()->forBranch($this->branch->id)->create([
        'is_active' => true,
        'is_available' => true,
    ]);
    MenuItemStation::create([
        'menu_item_id' => $menuItem->id,
        'kitchen_station_id' => $station->id,
    ]);

    $response = $this->actingAs($this->user)->get("/pos/{$outlet->id}");

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->component('pos/Terminal')
        ->has('outlet')
        ->has('reservations')
        ->has('menuItems')
    );
});

it('terminal page only shows menu items linked to the outlet via kitchen station', function () {
    $outlet = Outlet::factory()->forBranch($this->branch->id)->restaurant()->create();
    $station = KitchenStation::factory()->create([
        'branch_id' => $this->branch->id,
        'outlet_id' => $outlet->id,
    ]);
    $linkedItem = MenuItem::factory()->food()->forBranch($this->branch->id)->create(['is_active' => true, 'is_available' => true]);
    MenuItemStation::create(['menu_item_id' => $linkedItem->id, 'kitchen_station_id' => $station->id]);

    $unlinkedItem = MenuItem::factory()->food()->forBranch($this->branch->id)->create(['is_active' => true, 'is_available' => true]);

    $response = $this->actingAs($this->user)->get("/pos/{$outlet->id}");

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->component('pos/Terminal')
        ->where('menuItems.0.name', $linkedItem->name)
        ->missing('menuItems.1')
    );
});

it('terminal page excludes checked-out reservations', function () {
    Reservation::factory()->checkedOut()->create([
        'branch_id' => $this->branch->id,
        'room_id' => $this->room->id,
        'room_type_id' => $this->roomType->id,
    ]);
    $outlet = Outlet::factory()->forBranch($this->branch->id)->restaurant()->create();

    $response = $this->actingAs($this->user)->get("/pos/{$outlet->id}");

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->component('pos/Terminal')
        ->where('reservations.0.id', $this->reservation->id)
        ->where('reservations', fn ($reservations) => $reservations->count() === 1)
    );
});

it('can post a walk-in POS charge without a reservation', function () {
    $outlet = Outlet::factory()->forBranch($this->branch->id)->restaurant()->create();

    $response = $this->actingAs($this->user)->post("/pos/{$outlet->id}/charge", [
        'mode' => 'walk_in',
        'guest_title' => 'Mr.',
        'guest_name' => 'John Walkin',
        'items' => [
            ['name' => 'Coffee', 'quantity' => 2, 'price' => 350],
        ],
    ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('reservations', [
        'branch_id' => $this->branch->id,
        'source' => 'walk_in',
        'status' => 'checked_in',
        'guest_name' => 'Mr. John Walkin',
    ]);

    $walkInReservation = Reservation::where('source', 'walk_in')->where('guest_name', 'Mr. John Walkin')->first();
    $this->assertDatabaseHas('pos_charges', [
        'reservation_id' => $walkInReservation->id,
        'outlet' => $outlet->code,
    ]);
});

it('validates walk-in requires guest_title and guest_name', function () {
    $outlet = Outlet::factory()->forBranch($this->branch->id)->restaurant()->create();

    $response = $this->actingAs($this->user)->post("/pos/{$outlet->id}/charge", [
        'mode' => 'walk_in',
        'items' => [
            ['name' => 'Coffee', 'quantity' => 1, 'price' => 350],
        ],
    ]);

    $response->assertSessionHasErrors(['guest_title', 'guest_name']);
});
