<?php

use App\Models\Branch;
use App\Models\LaundryBatch;
use App\Models\LinenItem;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RoleSeeder::class);
    $this->branch = Branch::factory()->create();
    $this->user = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->user->branches()->attach($this->branch);
    $this->user->givePermissionTo('housekeeping.view');
    $this->user->givePermissionTo('housekeeping.manage');
});

it('renders the linen management page', function () {
    LinenItem::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->user)
        ->get(route('housekeeping.linen.index', $this->branch))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('housekeeping/LinenManagement')
            ->has('items', 1)
            ->has('summary')
        );
});

it('dispatches a batch via controller', function () {
    $item = LinenItem::factory()->create([
        'branch_id' => $this->branch->id,
        'current_dirty' => 40,
        'current_in_laundry' => 0,
    ]);

    $this->actingAs($this->user)
        ->post(route('housekeeping.linen.dispatch', $this->branch), [
            'item_counts' => [$item->id => 25],
            'vendor_type' => 'internal',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('laundry_batches', [
        'branch_id' => $this->branch->id,
        'vendor_type' => 'internal',
        'status' => 'sent',
    ]);

    expect($item->fresh()->current_dirty)->toBe(15)
        ->and($item->fresh()->current_in_laundry)->toBe(25);
});

it('receives a batch and records discard via controller', function () {
    $item = LinenItem::factory()->create([
        'branch_id' => $this->branch->id,
        'current_dirty' => 20,
        'current_in_laundry' => 20,
        'current_clean' => 10,
    ]);

    $batch = LaundryBatch::factory()->create([
        'branch_id' => $this->branch->id,
        'item_counts' => [$item->id => 20],
        'status' => 'sent',
        'dispatched_by' => $this->user->id,
    ]);

    $this->actingAs($this->user)
        ->post(route('housekeeping.linen.receive', [$this->branch, $batch]))
        ->assertRedirect();

    expect($batch->fresh()->status)->toBe('returned')
        ->and($item->fresh()->current_clean)->toBe(30);

    // Discard
    $this->actingAs($this->user)
        ->post(route('housekeeping.linen.discard', $this->branch), [
            'linen_item_id' => $item->id,
            'quantity' => 2,
            'reason' => 'torn',
            'from_pool' => 'dirty',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('linen_discards', [
        'branch_id' => $this->branch->id,
        'linen_item_id' => $item->id,
        'quantity' => 2,
        'reason' => 'torn',
    ]);
});
