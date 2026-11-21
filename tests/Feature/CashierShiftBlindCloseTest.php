<?php

use App\Models\Branch;
use App\Models\CashierShift;
use App\Models\Folio;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RoleSeeder::class);
    $this->branch = Branch::factory()->create();
    $this->cashier = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->cashier->branches()->attach($this->branch);
    $this->cashier->givePermissionTo('cashier.open_shift');
    $this->cashier->givePermissionTo('cashier.close_shift');
});

it('records a drop via cashier shift endpoint', function () {
    $shift = CashierShift::factory()->create([
        'branch_id' => $this->branch->id,
        'user_id' => $this->cashier->id,
        'status' => CashierShift::STATUS_OPEN,
    ]);

    $this->actingAs($this->cashier)
        ->post(route('cashier.drop', $shift), [
            'amount_minor' => 30000,
            'envelope_number' => 'ENV-999',
            'notes' => 'Midday safe transfer',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('cash_drops', [
        'cashier_shift_id' => $shift->id,
        'amount_minor' => 30000,
        'envelope_number' => 'ENV-999',
    ]);
});

it('performs blind close via cashier shift endpoint', function () {
    $shift = CashierShift::factory()->create([
        'branch_id' => $this->branch->id,
        'user_id' => $this->cashier->id,
        'opening_float_minor' => 10000,
        'status' => CashierShift::STATUS_OPEN,
    ]);

    $folio = Folio::factory()->create(['branch_id' => $this->branch->id]);

    Transaction::factory()->create([
        'folio_id' => $folio->id,
        'cashier_shift_id' => $shift->id,
        'type' => 'credit',
        'category' => 'payment',
        'description' => 'Cash payment - Bar',
        'amount' => 20000,
        'is_voided' => false,
    ]);

    $this->actingAs($this->cashier)
        ->post(route('cashier.blind-close', $shift), [
            'counted_cash_minor' => 30000,
        ])
        ->assertRedirect();

    expect($shift->fresh()->status)->toBe(CashierShift::STATUS_CLOSED)
        ->and($shift->fresh()->variance_minor)->toBe(0);
});
