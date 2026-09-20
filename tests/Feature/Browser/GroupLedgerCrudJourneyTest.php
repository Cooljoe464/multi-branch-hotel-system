<?php

use App\Models\Branch;
use App\Models\GroupLedger;
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

it('loads the group ledgers page via browser', function () {
    visit('/login')
        ->type('email', 'globaladmin@test.com')
        ->type('password', 'password')
        ->press('Log in')
        ->wait(3);

    visit('/group-ledgers')
        ->wait(2)
        ->assertSee('Group Ledger');
});

it('creates a group ledger entry via HTTP as global admin', function () {
    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post('/group-ledgers', [
            'business_date' => now()->toDateString(),
            'total_room_revenue' => 500000,
            'total_pos_revenue' => 150000,
            'total_tax' => 75000,
            'total_payments' => 400000,
            'net_revenue' => 575000,
            'currency_code' => 'NGN',
            'exchange_rate_to_group' => 1.0,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('group_ledgers', [
        'branch_id' => $this->branch->id,
        'total_room_revenue' => 500000,
        'total_pos_revenue' => 150000,
        'total_tax' => 75000,
        'currency_code' => 'NGN',
    ]);
});

it('updates a group ledger entry via HTTP as global admin', function () {
    $ledger = GroupLedger::factory()->create([
        'branch_id' => $this->branch->id,
        'total_room_revenue' => 300000,
        'total_pos_revenue' => 100000,
    ]);

    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->put("/group-ledgers/{$ledger->id}", [
            'total_room_revenue' => 350000,
            'total_pos_revenue' => 120000,
            'total_tax' => 60000,
            'total_payments' => 350000,
            'net_revenue' => 470000,
            'currency_code' => 'NGN',
            'exchange_rate_to_group' => 1.0,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('group_ledgers', [
        'id' => $ledger->id,
        'total_room_revenue' => 350000,
        'total_pos_revenue' => 120000,
    ]);
});

it('denies branch gm access to create group ledger entries', function () {
    $this->actingAs($this->gm)
        ->withSession(['branch_id' => $this->branch->id])
        ->post('/group-ledgers', [
            'business_date' => now()->subDay()->toDateString(),
            'total_room_revenue' => 200000,
            'total_pos_revenue' => 80000,
            'total_tax' => 35000,
            'total_payments' => 200000,
            'net_revenue' => 245000,
            'currency_code' => 'NGN',
            'exchange_rate_to_group' => 1.0,
        ])
        ->assertForbidden();
});

it('shows group ledgers list with data', function () {
    GroupLedger::factory()->create([
        'branch_id' => $this->branch->id,
    ]);

    $this->actingAs($this->admin)
        ->get('/group-ledgers')
        ->assertOk();
});
