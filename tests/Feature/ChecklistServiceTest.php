<?php

use App\Models\Branch;
use App\Models\OperationalChecklist;
use App\Models\User;
use App\Services\ChecklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new ChecklistService;
    $this->branch = Branch::factory()->create();
    $this->user = User::factory()->create();
});

it('creates checklist from template', function () {
    $tasks = ['Check lobby cleanliness', 'Verify cash drawer', 'Inspect fire exits'];

    $checklist = $this->service->createFromTemplate(
        $this->branch,
        'front_desk',
        'morning',
        'Morning Checklist',
        $tasks,
    );

    expect($checklist)->toBeInstanceOf(OperationalChecklist::class)
        ->and($checklist->items)->toHaveCount(3)
        ->and($checklist->items[0]['task'])->toBe('Check lobby cleanliness')
        ->and($checklist->items[0]['done'])->toBeFalse()
        ->and($checklist->status)->toBe('pending');
});

it('checks an item', function () {
    $checklist = $this->service->createFromTemplate(
        $this->branch,
        'front_desk',
        'morning',
        'Morning Checklist',
        ['Task A', 'Task B'],
    );

    $updated = $this->service->checkItem($checklist, 1, 'All good');

    expect($updated->items[0]['done'])->toBeTrue()
        ->and($updated->items[0]['checked_at'])->not->toBeNull()
        ->and($updated->items[0]['note'])->toBe('All good')
        ->and($updated->items[1]['done'])->toBeFalse();
});

it('completes a checklist when all items done', function () {
    $checklist = $this->service->createFromTemplate(
        $this->branch,
        'housekeeping',
        'evening',
        'Evening Checklist',
        ['Task A', 'Task B'],
    );

    $this->service->checkItem($checklist->fresh(), 1);
    $this->service->checkItem($checklist->fresh(), 2);

    $completed = $this->service->complete($checklist->fresh(), $this->user);

    expect($completed->status)->toBe('completed')
        ->and($completed->completed_by)->toBe($this->user->id)
        ->and($completed->completed_at)->not->toBeNull();
});

it('rejects completion with unchecked items', function () {
    $checklist = $this->service->createFromTemplate(
        $this->branch,
        'housekeeping',
        'morning',
        'Test',
        ['Task A', 'Task B'],
    );

    $this->service->checkItem($checklist->fresh(), 1);

    $this->service->complete($checklist->fresh(), $this->user);
})->throws(LogicException::class, 'All checklist items must be completed');

it('verifies a completed checklist', function () {
    $checklist = $this->service->createFromTemplate(
        $this->branch,
        'security',
        'night',
        'Night Checklist',
        ['Task A'],
    );

    $this->service->checkItem($checklist->fresh(), 1);
    $this->service->complete($checklist->fresh(), $this->user);

    $supervisor = User::factory()->create();
    $verified = $this->service->verify($checklist->fresh(), $supervisor);

    expect($verified->status)->toBe('reviewed')
        ->and($verified->verified_by)->toBe($supervisor->id);
});
