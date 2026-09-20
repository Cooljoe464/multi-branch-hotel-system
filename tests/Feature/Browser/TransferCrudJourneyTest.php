<?php

use App\Models\Branch;
use App\Models\TransferRequest;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;

uses(DatabaseMigrations::class);

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->branch = Branch::factory()->create();
    $this->otherBranch = Branch::factory()->create();

    $this->admin = User::factory()->create([
        'branch_id' => $this->branch->id,
        'email' => 'globaladmin@test.com',
        'password' => 'password',
        'is_global_admin' => true,
    ]);
    $this->admin->assignRole('Global Admin');
    $this->admin->branches()->syncWithoutDetaching([$this->branch->id, $this->otherBranch->id]);

    $this->gm = User::factory()->create([
        'branch_id' => $this->branch->id,
        'email' => 'branchgm@test.com',
        'password' => 'password',
    ]);
    $this->gm->assignRole('Branch GM');
    $this->gm->branches()->syncWithoutDetaching([$this->branch->id, $this->otherBranch->id]);
});

it('loads the transfers page via browser', function () {
    visit('/login')
        ->type('email', 'globaladmin@test.com')
        ->type('password', 'password')
        ->press('Log in')
        ->wait(3);

    visit('/transfers')
        ->wait(2)
        ->assertSee('Transfers');
});

it('creates a transfer request via HTTP as global admin', function () {
    $this->actingAs($this->admin)
        ->withSession(['branch_id' => $this->branch->id])
        ->post('/transfers', [
            'to_branch_id' => $this->otherBranch->id,
            'items' => [
                ['name' => 'Bath Towels', 'quantity' => 50],
                ['name' => 'Pillows', 'quantity' => 20],
            ],
            'notes' => 'Urgent transfer needed',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('transfer_requests', [
        'from_branch_id' => $this->branch->id,
        'to_branch_id' => $this->otherBranch->id,
        'requested_by' => $this->admin->id,
        'status' => 'pending',
    ]);
});

it('approves a transfer request via HTTP as branch gm', function () {
    $transfer = TransferRequest::factory()->pending()->create([
        'from_branch_id' => $this->otherBranch->id,
        'to_branch_id' => $this->branch->id,
        'requested_by' => $this->admin->id,
    ]);

    $this->actingAs($this->gm)
        ->withSession(['branch_id' => $this->branch->id])
        ->post("/transfers/{$transfer->id}/approve")
        ->assertRedirect();

    $this->assertDatabaseHas('transfer_requests', [
        'id' => $transfer->id,
        'status' => 'approved',
    ]);
});

it('ships a transfer request via HTTP as branch gm', function () {
    $transfer = TransferRequest::factory()->approved()->create([
        'from_branch_id' => $this->branch->id,
        'to_branch_id' => $this->otherBranch->id,
        'requested_by' => $this->admin->id,
    ]);

    $this->actingAs($this->gm)
        ->withSession(['branch_id' => $this->branch->id])
        ->post("/transfers/{$transfer->id}/ship")
        ->assertRedirect();

    $this->assertDatabaseHas('transfer_requests', [
        'id' => $transfer->id,
        'status' => 'in_transit',
    ]);
});

it('receives a transfer request via HTTP as branch gm', function () {
    $transfer = TransferRequest::factory()->inTransit()->create([
        'from_branch_id' => $this->branch->id,
        'to_branch_id' => $this->otherBranch->id,
        'requested_by' => $this->admin->id,
    ]);

    $this->actingAs($this->gm)
        ->withSession(['branch_id' => $this->otherBranch->id])
        ->post("/transfers/{$transfer->id}/receive")
        ->assertRedirect();

    $this->assertDatabaseHas('transfer_requests', [
        'id' => $transfer->id,
        'status' => 'received',
    ]);
});

it('creates a transfer request via HTTP as branch gm', function () {
    $this->actingAs($this->gm)
        ->withSession(['branch_id' => $this->branch->id])
        ->post('/transfers', [
            'to_branch_id' => $this->otherBranch->id,
            'items' => [
                ['name' => 'Soap Bars', 'quantity' => 100],
            ],
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('transfer_requests', [
        'from_branch_id' => $this->branch->id,
        'to_branch_id' => $this->otherBranch->id,
    ]);
});

it('shows transfers list with data', function () {
    TransferRequest::factory()->pending()->create([
        'from_branch_id' => $this->branch->id,
        'to_branch_id' => $this->otherBranch->id,
        'requested_by' => $this->admin->id,
    ]);

    $this->actingAs($this->admin)
        ->get('/transfers')
        ->assertOk();
});
