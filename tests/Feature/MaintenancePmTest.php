<?php

use App\Exceptions\AvailabilityException;
use App\Jobs\PmSchedulerJob;
use App\Models\Asset;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\JournalEntry;
use App\Models\MaintenanceTicket;
use App\Models\User;
use App\Services\MaintenanceService;
use Database\Seeders\ChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['timezone' => 'Africa/Lagos']);
    $this->user = $this->makeAdminUser($this->branch);
    $this->seed(ChartSeeder::class);
    $this->tech = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->tech->assignRole('Housekeeper');
    $this->tech->branches()->syncWithoutDetaching([$this->branch->id]);
    $this->asset = Asset::create([
        'branch_id' => $this->branch->id,
        'name' => 'AC Unit 101',
        'category' => 'hvac',
        'pm_schedule' => ['every_days' => 30, 'checklist' => ['filters', 'coils']],
    ]);
    $this->service = app(MaintenanceService::class);
});

function raiseTicket(string $priority = 'normal'): MaintenanceTicket
{
    return test()->service->raise(
        test()->branch,
        ['title' => 'AC leaking', 'category' => 'hvac', 'priority' => $priority, 'asset_id' => test()->asset->id],
        test()->user,
    );
}

it('schedules one PM work order per asset and never duplicates', function () {
    $first = (new PmSchedulerJob)->handle();

    expect($first['generated'])->toBe(1);
    expect(MaintenanceTicket::where('asset_id', $this->asset->id)->count())->toBe(1);
    expect($this->asset->fresh()->last_pm_at)->not->toBeNull();

    $second = (new PmSchedulerJob)->handle();

    expect($second['generated'])->toBe(0);
    expect(MaintenanceTicket::where('asset_id', $this->asset->id)->count())->toBe(1);
});

it('assigns once and detects breaches idempotently', function () {
    $ticket = raiseTicket('urgent');

    // Urgent defaults to a 4h clock.
    expect($ticket->sla_due_at)->not->toBeNull();

    $this->service->assign($ticket, $this->tech, $this->user);

    expect($ticket->fresh()->assigned_to)->toBe($this->tech->id);

    $other = User::factory()->create(['branch_id' => $this->branch->id]);

    expect(fn () => $this->service->assign($ticket->fresh(), $other, $this->user))
        ->toThrow(AvailabilityException::class, 'already has an assignee');

    // Force the clock past due: first check escalates, second is quiet.
    $ticket->update(['sla_due_at' => now()->subHour()]);

    expect($this->service->checkSla($ticket))->toBeTrue();
    expect($this->service->checkSla($ticket->fresh()))->toBeFalse();
});

it('resolves with parts, decrementing stock and journaling cost', function () {
    $screw = InventoryItem::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Screw M4',
        'category' => 'equipment',
        'unit' => 'piece',
        'current_quantity' => 10,
        'reorder_point' => 2,
        'cost_per_unit' => 150,
    ]);

    $ticket = raiseTicket();
    $this->service->assign($ticket, $this->tech, $this->user);

    $resolved = $this->service->resolve(
        $ticket,
        [['inventory_item_id' => $screw->id, 'qty' => 4]],
        'Replaced fan screws.',
        $this->tech,
    );

    expect($resolved->status)->toBe('completed');
    expect($screw->fresh()->current_quantity)->toBe(6.0);
    expect($resolved->actual_cost)->toBe(600);
    expect(JournalEntry::where('event', 'maintenance.parts')->where('amount_minor', 600)->count())->toBe(1);

    // Re-resolve is a no-op.
    expect($this->service->resolve($resolved->fresh(), [], null, $this->tech)->status)->toBe('completed');
    expect(JournalEntry::where('event', 'maintenance.parts')->count())->toBe(1);
});
