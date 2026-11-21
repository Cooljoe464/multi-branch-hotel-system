<?php

use App\Models\Branch;
use App\Models\OperationalChecklist;
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

it('renders operational checklists page', function () {
    OperationalChecklist::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($this->user)
        ->get(route('operations.checklists.index', $this->branch))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('operations/Checklists')
            ->has('checklists.data', 1)
        );
});

it('creates a checklist via controller', function () {
    $this->actingAs($this->user)
        ->post(route('operations.checklists.store', $this->branch), [
            'department' => 'front_desk',
            'shift_type' => 'morning',
            'title' => 'Front Desk Morning Checklist',
            'tasks' => ['Check safe float', 'Verify room keys', 'Review morning arrivals'],
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('operational_checklists', [
        'branch_id' => $this->branch->id,
        'department' => 'front_desk',
        'shift_type' => 'morning',
        'title' => 'Front Desk Morning Checklist',
        'status' => 'pending',
    ]);
});

it('checks items and completes checklist via controller', function () {
    $cl = OperationalChecklist::factory()->create([
        'branch_id' => $this->branch->id,
        'items' => [
            ['id' => 1, 'task' => 'Single Task', 'done' => false, 'checked_at' => null, 'note' => null],
        ],
        'status' => 'pending',
    ]);

    $this->actingAs($this->user)
        ->post(route('operations.checklists.check', [$this->branch, $cl, 1]), [
            'note' => 'Done promptly',
        ])
        ->assertRedirect();

    expect($cl->fresh()->items[0]['done'])->toBeTrue();

    $this->actingAs($this->user)
        ->post(route('operations.checklists.complete', [$this->branch, $cl]))
        ->assertRedirect();

    expect($cl->fresh()->status)->toBe('completed');
});
