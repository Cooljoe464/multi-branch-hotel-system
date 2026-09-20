<?php

use App\Models\Branch;
use App\Models\CityLedgerAccount;
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

    $this->gm = User::factory()->create([
        'branch_id' => $this->branch->id,
        'email' => 'branchgm@test.com',
        'password' => 'password',
    ]);
    $this->gm->assignRole('Branch GM');
    $this->gm->branches()->syncWithoutDetaching([$this->branch->id]);
});

it('loads the city ledger page via browser', function () {
    visit('/login')
        ->type('email', 'globaladmin@test.com')
        ->type('password', 'password')
        ->press('Log in')
        ->wait(3);

    visit('/city-ledger')
        ->wait(2)
        ->assertSee('City Ledger');
});

it('creates a city ledger account via HTTP as global admin', function () {
    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post('/city-ledger', [
            'company_name' => 'Acme Corp',
            'contact_name' => 'John Smith',
            'email' => 'john@acme.com',
            'phone' => '+2348012345678',
            'credit_limit' => 500000,
            'payment_terms_days' => 30,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('city_ledger_accounts', [
        'branch_id' => $this->branch->id,
        'company_name' => 'Acme Corp',
        'contact_name' => 'John Smith',
        'email' => 'john@acme.com',
        'credit_limit' => 500000,
        'payment_terms_days' => 30,
    ]);
});

it('updates a city ledger account via HTTP as global admin', function () {
    $account = CityLedgerAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'company_name' => 'Old Company',
        'credit_limit' => 100000,
    ]);

    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->put("/city-ledger/{$account->id}", [
            'company_name' => 'Updated Company',
            'contact_name' => 'Jane Doe',
            'email' => 'jane@updated.com',
            'credit_limit' => 200000,
            'payment_terms_days' => 45,
            'is_active' => true,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('city_ledger_accounts', [
        'id' => $account->id,
        'company_name' => 'Updated Company',
        'credit_limit' => 200000,
        'payment_terms_days' => 45,
    ]);
});

it('charges a city ledger account via HTTP as global admin', function () {
    $account = CityLedgerAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'balance_owing' => 0,
    ]);

    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post("/city-ledger/{$account->id}/charge", [
            'amount' => 25000,
            'reference' => 'INV-001',
            'notes' => 'January invoice',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('city_ledger_transactions', [
        'city_ledger_account_id' => $account->id,
        'type' => 'debit',
        'amount' => 25000,
        'reference' => 'INV-001',
    ]);
});

it('records payment on city ledger via HTTP as global admin', function () {
    $account = CityLedgerAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'balance_owing' => 50000,
    ]);

    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post("/city-ledger/{$account->id}/pay", [
            'amount' => 30000,
            'reference' => 'PAY-001',
            'notes' => 'Partial payment',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('city_ledger_transactions', [
        'city_ledger_account_id' => $account->id,
        'type' => 'payment',
        'amount' => 30000,
        'reference' => 'PAY-001',
    ]);
});

it('denies branch gm access to create city ledger accounts', function () {
    $this->actingAs($this->gm)
        ->withSession(['branch_id' => $this->branch->id])
        ->post('/city-ledger', [
            'company_name' => 'GM Corp',
            'contact_name' => 'GM Contact',
            'email' => 'gm@gmcorp.com',
            'credit_limit' => 300000,
            'payment_terms_days' => 15,
        ])
        ->assertForbidden();
});

it('shows city ledger list with data', function () {
    CityLedgerAccount::factory()->create([
        'branch_id' => $this->branch->id,
        'company_name' => 'Listed Corp',
    ]);

    $this->actingAs($this->admin)
        ->get('/city-ledger')
        ->assertOk();
});
