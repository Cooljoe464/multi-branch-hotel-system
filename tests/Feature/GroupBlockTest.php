<?php

use App\Exceptions\AvailabilityException;
use App\Imports\RoomingListImport;
use App\Jobs\CutoffJob;
use App\Models\BanquetEventOrder;
use App\Models\Branch;
use App\Models\Folio;
use App\Models\FunctionSpace;
use App\Models\GroupBlock;
use App\Models\JournalEntry;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Transaction;
use App\Services\AvailabilityService;
use App\Services\GroupBlockService;
use Carbon\Carbon;
use Database\Seeders\ChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['timezone' => 'Africa/Lagos']);
    $this->user = $this->makeAdminUser($this->branch);
    $this->seed(ChartSeeder::class);
    $this->roomType = RoomType::factory()->create(['branch_id' => $this->branch->id, 'base_rate' => 20000, 'code' => 'DLX']);
    Room::factory()->count(10)->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'status' => 'available',
    ]);
    $this->service = app(GroupBlockService::class);
    $this->in = Carbon::now()->addDays(5)->toDateString();
    $this->out = Carbon::now()->addDays(8)->toDateString();
});

function holdBlock(string $code = 'WED-001'): GroupBlock
{
    return app(GroupBlockService::class)->create(
        test()->branch,
        ['name' => 'Wedding', 'code' => $code, 'cutoff_date' => test()->in, 'attrition_pct' => 10],
        [['room_type_id' => test()->roomType->id, 'from' => test()->in, 'to' => test()->out, 'blocked' => 10]],
    );
}

function pickupAttrs(string $guest): array
{
    return [
        'guest_name' => $guest,
        'adults' => 2,
        'children' => 0,
        'room_rate' => 20000,
        'total_amount' => 20000,
        'status' => 'confirmed',
        'source' => 'group_block',
        'payment_status' => 'pending',
    ];
}

it('holds inventory, picks up six and releases four at cutoff', function () {
    $block = holdBlock();

    expect(app(AvailabilityService::class)->sellableFor($this->branch, $this->roomType, $this->in))->toBe(0);

    foreach (range(1, 6) as $i) {
        $next = Carbon::parse($this->in)->addDay()->toDateString();

        $this->service->pickup(
            $block, $this->roomType, $this->in, $next,
            pickupAttrs("Guest {$i}"),
            null, (string) Str::uuid(),
        );
    }

    expect($block->nights()->where('stay_date', $this->in)->first()->picked_up)->toBe(6);

    $block->update(['cutoff_date' => Carbon::now()->subDay()->toDateString()]);

    $result = (new CutoffJob)->handle();

    // +5d: 4 left; +6d and +7d: 10 each → 24 released.
    expect($result)->toBe(['released_blocks' => 1, 'released_nights' => 24]);
    expect($block->fresh()->status)->toBe(GroupBlock::STATUS_COMPLETED);

    // Six picked stays hold real inventory on night one; the other
    // nights return to full sellable after release.
    expect(app(AvailabilityService::class)->sellableFor($this->branch, $this->roomType, $this->in))->toBe(4);

    foreach ([1, 2] as $offset) {
        $date = Carbon::parse($this->in)->addDays($offset)->toDateString();

        expect(app(AvailabilityService::class)->sellableFor($this->branch, $this->roomType, $date))->toBe(10);
    }

    // Release touches inventory only: no master folio was conjured.
    expect(Folio::where('type', Folio::TYPE_MASTER)->count())->toBe(0);

    // Re-run is a no-op.
    expect((new CutoffJob)->handle())->toBe(['released_blocks' => 0, 'released_nights' => 0]);
});

it('lets one pickup win the last held night', function () {
    $block = app(GroupBlockService::class)->create(
        $this->branch,
        ['name' => 'Small', 'code' => 'SM-001', 'cutoff_date' => $this->in],
        [['room_type_id' => $this->roomType->id, 'from' => $this->in, 'to' => Carbon::parse($this->in)->addDay()->toDateString(), 'blocked' => 1]],
    );

    $next = Carbon::parse($this->in)->addDay()->toDateString();

    $winner = $this->service->pickup($block, $this->roomType, $this->in, $next, pickupAttrs('Winner'), null, (string) Str::uuid());

    expect($winner->group_block_id)->toBe($block->id)
        ->and($winner->is_group_booking)->toBeTrue();

    expect(fn () => $this->service->pickup($block, $this->roomType, $this->in, $next, pickupAttrs('Loser'), null, (string) Str::uuid()))
        ->toThrow(AvailabilityException::class, 'No held nights left');
});

it('imports rooming lists idempotently', function () {
    $block = app(GroupBlockService::class)->create(
        $this->branch,
        ['name' => 'Conference', 'code' => 'CONF-001', 'cutoff_date' => $this->in],
        [['room_type_id' => $this->roomType->id, 'from' => $this->in, 'to' => $this->out, 'blocked' => 5]],
    );

    $rows = [
        ['guest_name' => 'Anna', 'guest_email' => 'anna@example.com', 'room_type_code' => 'DLX', 'check_in_date' => $this->in, 'check_out_date' => Carbon::parse($this->in)->addDay()->toDateString(), 'adults' => 2],
        ['guest_name' => 'Ben', 'guest_email' => 'ben@example.com', 'room_type_code' => 'DLX', 'check_in_date' => $this->in, 'check_out_date' => Carbon::parse($this->in)->addDay()->toDateString(), 'adults' => 1],
    ];

    $first = new RoomingListImport($this->branch->id, $block->id);
    foreach ($rows as $row) {
        $first->model($row);
    }

    expect($first->getCreatedCount())->toBe(2);

    $second = new RoomingListImport($this->branch->id, $block->id);
    foreach ($rows as $row) {
        $second->model($row);
    }

    expect($second->getCreatedCount())->toBe(0)
        ->and($second->getSkippedCount())->toBe(2)
        ->and($block->reservations()->count())->toBe(2);
});

it('posts a BEO once to the master folio with a journal pair', function () {
    $block = holdBlock('BEO-001');

    $space = FunctionSpace::create(['branch_id' => $this->branch->id, 'name' => 'Grand Ballroom', 'capacity' => 300]);

    $beo = BanquetEventOrder::create([
        'branch_id' => $this->branch->id,
        'group_block_id' => $block->id,
        'function_space_id' => $space->id,
        'event_date' => $this->in,
        'agreed_total_minor' => 10064,
        'status' => 'draft',
    ]);

    $charge = $this->service->postBeo($beo, $this->user);

    expect($charge->category)->toBe('banquet')
        ->and($charge->amount)->toBe(10064)
        ->and($charge->window->code)->toBe('banquet')
        ->and($beo->fresh()->status)->toBe('posted');

    expect($block->fresh()->master_folio_id)->toBe($charge->folio_id);
    expect(JournalEntry::where('event', 'charge.posted')->where('amount_minor', 10064)->count())->toBe(1);

    $again = $this->service->postBeo($beo->fresh(), $this->user);

    expect($again->id)->toBe($charge->id);
    expect(Transaction::where('category', 'banquet')->where('is_voided', false)->count())->toBe(1);
    expect(JournalEntry::where('event', 'charge.posted')->where('amount_minor', 10064)->count())->toBe(1);
});
