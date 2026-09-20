<?php

use App\Models\Branch;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\User;
use App\Services\FolioService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;

uses(DatabaseMigrations::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->branch = Branch::factory()->create();
    $this->roomType = RoomType::factory()->create(['branch_id' => $this->branch->id]);
    $this->room = Room::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'number' => '201',
        'status' => 'available',
    ]);

    $this->user = User::factory()->create([
        'branch_id' => $this->branch->id,
        'email' => 'frontdesk@test.com',
        'password' => 'password',
    ]);
    $this->user->assignRole('Front Desk');
    $this->user->branches()->syncWithoutDetaching([$this->branch->id]);

    $this->reservation = Reservation::factory()->checkedIn()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'room_id' => $this->room->id,
        'guest_name' => 'Folio Test Guest',
    ]);

    $service = new FolioService;
    $this->folio = $service->createFolio($this->branch->id, $this->reservation->id);
});

it('views folio list with data', function () {
    visit('/login')
        ->type('email', 'frontdesk@test.com')
        ->type('password', 'password')
        ->press('Log in')
        ->wait(3)
        ->click('Folios')
        ->wait(2)
        ->assertSee('Folios')
        ->assertSee($this->folio->folio_number)
        ->assertSee('Folio Test Guest')
        ->assertSee('individual');
});

it('views folio detail with transactions', function () {
    visit('/login')
        ->type('email', 'frontdesk@test.com')
        ->type('password', 'password')
        ->press('Log in')
        ->wait(3);

    visit("/folios/{$this->folio->id}")
        ->wait(3)
        ->assertSee($this->folio->folio_number)
        ->assertSee('Post Charge')
        ->assertSee('Record Payment')
        ->assertSee('Transactions')
        ->assertSee('No transactions yet');
});

it('posts a charge to an open folio via HTTP', function () {
    $this->actingAs($this->user)
        ->withSession(['branch_id' => $this->branch->id])
        ->post("/folios/{$this->folio->id}/charges", [
            'category' => 'minibar',
            'description' => 'Minibar - Beer x2',
            'amount' => 3000,
            'tax_rate_bps' => 750,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('transactions', [
        'folio_id' => $this->folio->id,
        'type' => 'debit',
        'category' => 'minibar',
        'amount' => 3000,
        'description' => 'Minibar - Beer x2',
    ]);
});

it('records a payment on an open folio via HTTP', function () {
    $this->actingAs($this->user)
        ->withSession(['branch_id' => $this->branch->id])
        ->post("/folios/{$this->folio->id}/payments", [
            'amount' => 10000,
            'method' => 'card',
            'reference' => 'CC-RECEIPT-789',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('transactions', [
        'folio_id' => $this->folio->id,
        'type' => 'credit',
        'category' => 'payment',
        'amount' => 10000,
    ]);
});

it('creates a child folio via HTTP', function () {
    $this->actingAs($this->user)
        ->withSession(['branch_id' => $this->branch->id])
        ->post("/folios/{$this->folio->id}/child", [
            'description' => 'Corporate bill - Acme Corp',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('folios', [
        'parent_folio_id' => $this->folio->id,
        'branch_id' => $this->branch->id,
        'description' => 'Corporate bill - Acme Corp',
        'type' => 'child',
        'status' => 'open',
    ]);
});

it('checkouts a settled folio via HTTP', function () {
    $this->folio->update(['balance' => 0]);

    $this->actingAs($this->user)
        ->withSession(['branch_id' => $this->branch->id])
        ->post("/folios/{$this->folio->id}/checkout")
        ->assertRedirect();

    $this->folio->refresh();
    expect($this->folio->status)->toBe('closed');
    expect($this->folio->is_settled)->toBeTrue();
});
