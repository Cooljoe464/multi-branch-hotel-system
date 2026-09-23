<?php

use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Models\HousekeepingTask;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\HousekeepingService;
use Carbon\Carbon;
use Database\Seeders\ChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create(['timezone' => 'Africa/Lagos']);
    $this->user = $this->makeAdminUser($this->branch);
    $this->seed(ChartSeeder::class);
    $this->roomType = RoomType::factory()->create(['branch_id' => $this->branch->id, 'base_rate' => 20000]);
    $this->rooms = Room::factory()->count(3)->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $this->roomType->id,
        'status' => 'available',
    ]);
    $this->attendant = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->attendant->assignRole('Housekeeper');
    $this->attendant->branches()->syncWithoutDetaching([$this->branch->id]);
    $this->service = app(HousekeepingService::class);
});

function hkTask(int $branchId, int $roomId, string $kind = 'stayover', int $credits = 10): HousekeepingTask
{
    return HousekeepingTask::create([
        'branch_id' => $branchId,
        'room_id' => $roomId,
        'kind' => $kind,
        'credits' => $credits,
        'status' => 'open',
    ]);
}

it('caps attendant allocation at the credit limit', function () {
    $this->branch->update(['settings' => ['hk_credit_cap' => 20]]);

    $first = hkTask($this->branch->id, $this->rooms[0]->id, 'checkout_clean', 15);
    $second = hkTask($this->branch->id, $this->rooms[1]->id, 'stayover', 10);

    $this->service->assign($first, $this->attendant, $this->user);

    expect($first->fresh()->assignee_id)->toBe($this->attendant->id);

    expect(fn () => $this->service->assign($second, $this->attendant, $this->user))
        ->toThrow(AvailabilityException::class, 'at 15 credits');
});

it('completes idempotently and reopens failed inspections', function () {
    $task = hkTask($this->branch->id, $this->rooms[0]->id, 'checkout_clean', 15);
    $this->service->assign($task, $this->attendant, $this->user);

    // Checkout cleans need a score.
    expect(fn () => $this->service->complete($task, $this->attendant))
        ->toThrow(AvailabilityException::class, 'inspection score');

    // Below threshold: failed + fresh task spawned.
    $failed = $this->service->complete($task->fresh(), $this->attendant, 50);

    expect($failed->status)->toBe(HousekeepingTask::STATUS_FAILED_INSPECTION);
    expect(HousekeepingTask::where('room_id', $this->rooms[0]->id)
        ->where('status', HousekeepingTask::STATUS_OPEN)->count())->toBe(1);

    // Passing score closes; re-run is a no-op.
    $done = $this->service->complete($failed->fresh(), $this->attendant, 90);

    expect($done->status)->toBe(HousekeepingTask::STATUS_DONE);
    expect($this->service->complete($done->fresh(), $this->attendant, 90)->status)
        ->toBe(HousekeepingTask::STATUS_DONE);
    expect($this->rooms[0]->fresh()->condition)->toBe('inspected');
});

it('posts minibar charges exactly once per idempotency key', function () {
    $in = Carbon::now()->subDay()->toDateString();
    $out = Carbon::now()->addDays(2)->toDateString();

    app(AvailabilityService::class)->reserve(
        $this->branch, $this->roomType, $in, $out,
        [
            'guest_name' => 'Minibar Guest', 'adults' => 2, 'children' => 0,
            'room_rate' => 20000, 'total_amount' => 60000,
            'status' => 'checked_in', 'source' => 'direct', 'payment_status' => 'pending',
        ],
        $this->rooms[0]->id, (string) Str::uuid(),
    );

    $items = [
        ['name' => 'Cola', 'qty' => 2, 'unit_price_minor' => 500],
        ['name' => 'Nuts', 'qty' => 1, 'unit_price_minor' => 1500],
    ];

    $first = $this->service->postMinibar($this->rooms[0], $items, $this->user, 'mini-1');
    $second = $this->service->postMinibar($this->rooms[0]->fresh(), $items, $this->user, 'mini-1');

    expect($first->amount)->toBe(2500)
        ->and($first->category)->toBe('minibar')
        ->and($second->id)->toBe($first->id);
    expect(Transaction::where('category', 'minibar')->where('is_voided', false)->count())->toBe(1);
});

it('drops inventory for OOO spans but not for OOS flags', function () {
    $from = Carbon::now()->addDays(5)->toDateString();
    $to = Carbon::now()->addDays(7)->toDateString();
    $service = app(AvailabilityService::class);

    expect($service->sellableFor($this->branch, $this->roomType, $from))->toBe(3);

    $out = $this->service->setOutOfOrder($this->rooms[0], $from, $to, 'Plumbing', $this->user);

    expect($service->sellableFor($this->branch, $this->roomType, $from))->toBe(2);
    expect($this->rooms[0]->fresh()->condition)->toBe('ooo');

    $this->service->setCondition($this->rooms[1], 'oos', 'Touch-up paint', $this->user);

    expect($service->sellableFor($this->branch, $this->roomType, $from))->toBe(2);

    $this->service->clearOutOfOrder($out, $this->user);

    expect($service->sellableFor($this->branch, $this->roomType, $from))->toBe(3);
    expect($this->rooms[0]->fresh()->condition)->toBe('clean');
});

it('stores inspection photos on R2', function () {
    Storage::fake('r2');

    $task = hkTask($this->branch->id, $this->rooms[0]->id, 'inspection', 5);
    $photo = UploadedFile::fake()->image('bathroom.jpg');

    $done = $this->service->complete($task, $this->attendant, 95, [$photo]);

    expect($done->status)->toBe(HousekeepingTask::STATUS_DONE);
    expect($done->photo_paths)->toHaveCount(1);
    Storage::disk('r2')->assertExists($done->photo_paths[0]);
});
