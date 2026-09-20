<?php

use App\Models\Branch;
use App\Models\MenuItem;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\TabletOrder;
use App\Models\TabletSession;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;

uses(DatabaseMigrations::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->branch = Branch::factory()->create();

    $this->admin = User::factory()->create([
        'branch_id' => $this->branch->id,
        'email' => 'globaladmin@test.com',
        'password' => 'password',
        'is_global_admin' => true,
    ]);
    $this->admin->assignRole('Global Admin');
    $this->admin->branches()->syncWithoutDetaching([$this->branch->id]);

    $this->roomType = RoomType::factory()->create([
        'branch_id' => $this->branch->id,
    ]);

    $this->room = Room::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'status' => 'occupied',
    ]);

    $this->menuItem = MenuItem::factory()->food()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Club Sandwich',
        'price' => 2500,
    ]);

    $this->tabletSession = TabletSession::factory()->create([
        'branch_id' => $this->branch->id,
        'room_id' => $this->room->id,
    ]);
});

it('creates a tablet order via HTTP as global admin', function () {
    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post('/tablet/orders', [
            'session_id' => $this->tabletSession->id,
            'items' => [
                ['menu_item_id' => $this->menuItem->id, 'quantity' => 2],
            ],
            'payment_method' => 'room_charge',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('tablet_orders', [
        'branch_id' => $this->branch->id,
        'tablet_session_id' => $this->tabletSession->id,
        'status' => 'pending',
    ]);
});

it('creates a tablet order with multiple items via HTTP', function () {
    $otherItem = MenuItem::factory()->food()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Fresh Juice',
        'price' => 1200,
    ]);

    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post('/tablet/orders', [
            'session_id' => $this->tabletSession->id,
            'items' => [
                ['menu_item_id' => $this->menuItem->id, 'quantity' => 1],
                ['menu_item_id' => $otherItem->id, 'quantity' => 2],
            ],
            'payment_method' => 'room_charge',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('tablet_orders', [
        'branch_id' => $this->branch->id,
        'tablet_session_id' => $this->tabletSession->id,
    ]);
});

it('cancels a tablet order via HTTP as global admin', function () {
    $order = TabletOrder::factory()->create([
        'branch_id' => $this->branch->id,
        'tablet_session_id' => $this->tabletSession->id,
        'status' => 'pending',
    ]);

    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post("/tablet/orders/{$order->id}/cancel")
        ->assertRedirect();

    $this->assertDatabaseHas('tablet_orders', [
        'id' => $order->id,
        'status' => 'cancelled',
    ]);
});

it('shows tablet order tracking via HTTP', function () {
    $order = TabletOrder::factory()->create([
        'branch_id' => $this->branch->id,
        'tablet_session_id' => $this->tabletSession->id,
    ]);

    $this->actingAs($this->admin)
        ->get("/tablet/orders/{$order->id}/track")
        ->assertOk();
});
