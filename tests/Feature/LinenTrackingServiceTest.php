<?php

use App\Models\Branch;
use App\Models\LinenItem;
use App\Models\User;
use App\Services\LinenTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new LinenTrackingService;
    $this->branch = Branch::factory()->create();
    $this->user = User::factory()->create();
});

it('dispatches a laundry batch', function () {
    $item = LinenItem::factory()->create([
        'branch_id' => $this->branch->id,
        'current_dirty' => 50,
        'current_in_laundry' => 0,
    ]);

    $batch = $this->service->dispatchBatch(
        $this->branch,
        $this->user,
        [$item->id => 20],
    );

    expect($batch->status)->toBe('sent')
        ->and($batch->item_counts)->toBe([$item->id => 20]);

    $item->refresh();
    expect($item->current_dirty)->toBe(30)
        ->and($item->current_in_laundry)->toBe(20);
});

it('receives a laundry batch', function () {
    $item = LinenItem::factory()->create([
        'branch_id' => $this->branch->id,
        'current_dirty' => 50,
        'current_in_laundry' => 0,
        'current_clean' => 10,
    ]);

    $batch = $this->service->dispatchBatch($this->branch, $this->user, [$item->id => 20]);
    $receiver = User::factory()->create();
    $returned = $this->service->receiveBatch($batch, $receiver);

    expect($returned->status)->toBe('returned')
        ->and($returned->received_by)->toBe($receiver->id);

    $item->refresh();
    expect($item->current_in_laundry)->toBe(0)
        ->and($item->current_clean)->toBe(30);
});

it('records linen discard', function () {
    $item = LinenItem::factory()->create([
        'branch_id' => $this->branch->id,
        'current_dirty' => 20,
        'unit_cost_minor' => 100000,
    ]);

    $discard = $this->service->recordDiscard(
        $this->branch,
        $item->id,
        5,
        'torn',
        $this->user,
    );

    expect($discard->quantity)->toBe(5)
        ->and($discard->total_loss_minor)->toBe(500000)
        ->and($discard->unit_cost_minor)->toBe(100000);

    expect($item->fresh()->current_dirty)->toBe(15);
});

it('returns par level alerts', function () {
    LinenItem::factory()->create([
        'branch_id' => $this->branch->id,
        'par_level' => 100,
        'current_clean' => 20,
        'current_in_laundry' => 10,
    ]);

    LinenItem::factory()->create([
        'branch_id' => $this->branch->id,
        'par_level' => 50,
        'current_clean' => 40,
        'current_in_laundry' => 20,
    ]);

    $alerts = $this->service->parLevelAlerts($this->branch);

    expect($alerts)->toHaveCount(1); // Only first item (30 < 100)
});

it('generates inventory summary', function () {
    LinenItem::factory()->create([
        'branch_id' => $this->branch->id,
        'category' => 'bed_linen',
        'current_clean' => 50,
        'current_dirty' => 10,
        'current_in_laundry' => 5,
        'par_level' => 100,
    ]);

    LinenItem::factory()->create([
        'branch_id' => $this->branch->id,
        'category' => 'bed_linen',
        'current_clean' => 30,
        'current_dirty' => 5,
        'current_in_laundry' => 10,
        'par_level' => 80,
    ]);

    $summary = $this->service->inventorySummary($this->branch);

    expect($summary)->toHaveKey('bed_linen')
        ->and($summary['bed_linen']['total_clean'])->toBe(80)
        ->and($summary['bed_linen']['total_dirty'])->toBe(15)
        ->and($summary['bed_linen']['total_in_laundry'])->toBe(15)
        ->and($summary['bed_linen']['total_par'])->toBe(180);
});
