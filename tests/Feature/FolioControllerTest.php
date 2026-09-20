<?php

use App\Models\Branch;
use App\Models\Folio;
use App\Models\Reservation;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create();
    $this->user = $this->makeAdminUser($this->branch);
    $this->reservation = Reservation::factory()->checkedIn()->create([
        'branch_id' => $this->branch->id,
    ]);
});

it('can list folios for the branch', function () {
    Folio::create([
        'branch_id' => $this->branch->id,
        'folio_number' => Folio::generateFolioNumber(),
        'type' => 'individual',
        'status' => 'open',
    ]);

    $response = $this->actingAs($this->user)->get('/folios');

    $response->assertStatus(200);
});

it('can show a folio with transactions', function () {
    $folio = Folio::create([
        'branch_id' => $this->branch->id,
        'reservation_id' => $this->reservation->id,
        'folio_number' => Folio::generateFolioNumber(),
        'type' => 'individual',
        'status' => 'open',
    ]);
    Transaction::factory()->count(3)->create(['folio_id' => $folio->id]);

    $response = $this->actingAs($this->user)->get("/folios/{$folio->id}");

    $response->assertStatus(200);
});

it('can create a child folio', function () {
    $master = Folio::create([
        'branch_id' => $this->branch->id,
        'folio_number' => Folio::generateFolioNumber(),
        'type' => 'master',
        'status' => 'open',
        'description' => 'Corporate Account',
    ]);

    $response = $this->actingAs($this->user)->post("/folios/{$master->id}/child", [
        'description' => 'Corporate Bill',
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('folios', [
        'parent_folio_id' => $master->id,
        'type' => 'child',
        'description' => 'Corporate Bill',
        'branch_id' => $this->branch->id,
    ]);
});

it('can transfer a transaction between folios', function () {
    $master = Folio::create([
        'branch_id' => $this->branch->id,
        'folio_number' => Folio::generateFolioNumber(),
        'type' => 'master',
        'status' => 'open',
    ]);
    $child = Folio::create([
        'branch_id' => $this->branch->id,
        'parent_folio_id' => $master->id,
        'folio_number' => Folio::generateFolioNumber(),
        'type' => 'child',
        'status' => 'open',
    ]);

    $transaction = Transaction::factory()->debit()->create([
        'folio_id' => $master->id,
        'amount' => 5000,
    ]);

    $response = $this->actingAs($this->user)->post("/folios/transactions/{$transaction->id}/transfer", [
        'target_folio_id' => $child->id,
    ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('transactions', [
        'id' => $transaction->id,
        'is_voided' => true,
    ]);

    $this->assertDatabaseHas('transactions', [
        'folio_id' => $child->id,
        'category' => $transaction->category,
        'amount' => $transaction->amount,
        'is_voided' => false,
    ]);
});

it('prevents transferring to the same folio', function () {
    $folio = Folio::create([
        'branch_id' => $this->branch->id,
        'reservation_id' => $this->reservation->id,
        'folio_number' => Folio::generateFolioNumber(),
        'type' => 'individual',
        'status' => 'open',
    ]);
    $transaction = Transaction::factory()->debit()->create(['folio_id' => $folio->id]);

    $response = $this->actingAs($this->user)->post("/folios/transactions/{$transaction->id}/transfer", [
        'target_folio_id' => $folio->id,
    ]);

    $response->assertSessionHasErrors('target_folio_id');
});

it('validates child folio required fields', function () {
    $master = Folio::create([
        'branch_id' => $this->branch->id,
        'folio_number' => Folio::generateFolioNumber(),
        'type' => 'master',
        'status' => 'open',
    ]);

    $response = $this->actingAs($this->user)->post("/folios/{$master->id}/child", []);

    $response->assertSessionHasErrors('description');
});
