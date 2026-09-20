<?php

use App\Models\Branch;
use App\Models\Folio;
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

    $this->cashier = User::factory()->create([
        'branch_id' => $this->branch->id,
        'email' => 'cashier@test.com',
        'password' => 'password',
    ]);
    $this->cashier->assignRole('Cashier');
    $this->cashier->branches()->syncWithoutDetaching([$this->branch->id]);
});

it('loads the folios page via browser', function () {
    visit('/login')
        ->type('email', 'globaladmin@test.com')
        ->type('password', 'password')
        ->press('Log in')
        ->wait(3);

    visit('/folios')
        ->wait(2)
        ->assertSee('Folios');
});

it('creates a staff folio via HTTP as global admin', function () {
    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post('/folios', [
            'type' => 'staff',
            'guest_name' => 'Staff Member',
            'description' => 'Staff accommodation',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('folios', [
        'branch_id' => $this->branch->id,
        'type' => 'staff',
        'guest_name' => 'Staff Member',
    ]);
});

it('creates a non-guest folio via HTTP as global admin', function () {
    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post('/folios', [
            'type' => 'non_guest',
            'guest_name' => 'Walk-in Customer',
            'description' => 'Restaurant charge',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('folios', [
        'branch_id' => $this->branch->id,
        'type' => 'non_guest',
        'guest_name' => 'Walk-in Customer',
    ]);
});

it('posts a charge to a folio via HTTP as global admin', function () {
    $folio = Folio::factory()->create([
        'branch_id' => $this->branch->id,
    ]);

    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post("/folios/{$folio->id}/charges", [
            'category' => 'restaurant',
            'description' => 'Dinner at restaurant',
            'amount' => 15000,
            'tax_rate_bps' => 750,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('transactions', [
        'folio_id' => $folio->id,
        'type' => 'debit',
        'category' => 'restaurant',
        'amount' => 15000,
    ]);
});

it('records a payment on a folio via HTTP as global admin', function () {
    $folio = Folio::factory()->create([
        'branch_id' => $this->branch->id,
    ]);

    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post("/folios/{$folio->id}/payments", [
            'amount' => 20000,
            'method' => 'cash',
            'reference' => 'CASH-001',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('transactions', [
        'folio_id' => $folio->id,
        'type' => 'credit',
        'amount' => 20000,
    ]);
});

it('creates a staff folio via HTTP as cashier', function () {
    $this->actingAs($this->cashier)
        ->withSession(['branch_id' => $this->branch->id])
        ->post('/folios', [
            'type' => 'staff',
            'guest_name' => 'Cashier Staff',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('folios', [
        'branch_id' => $this->branch->id,
        'guest_name' => 'Cashier Staff',
    ]);
});

it('shows folios list with data', function () {
    Folio::factory()->create([
        'branch_id' => $this->branch->id,
        'guest_name' => 'Listed Guest',
    ]);

    $this->actingAs($this->admin)
        ->get('/folios')
        ->assertOk();
});
