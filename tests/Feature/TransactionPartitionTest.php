<?php

use App\Models\Branch;
use App\Models\PosCharge;
use App\Models\Transaction;
use App\Services\BusinessDateService;
use App\Services\FolioService;
use App\Services\PartitionManager;
use Database\Seeders\ChartSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create();
    $this->user = $this->makeAdminUser($this->branch);
    $this->seed(ChartSeeder::class);
    app(BusinessDateService::class)->current($this->branch);
    $this->service = new FolioService;
    $this->folio = $this->service->createStaffFolio($this->branch->id, 'Partition Guest');
});

function seedMonth(object $test, string $date, int $count): void
{
    foreach (range(1, $count) as $i) {
        Transaction::create([
            'folio_id' => $test->folio->id,
            'business_date' => $date,
            'currency_code' => 'NGN',
            'type' => 'debit',
            'category' => 'misc',
            'description' => "Seed {$date} #{$i}",
            'amount' => 1000,
        ]);
    }
}

it('reports readiness with blockers before the cutover', function () {
    $report = (new PartitionManager)->readiness();

    expect($report['transactions']['partitioned'])->toBeFalse()
        ->and($report['transactions']['blockers'])->not->toBeEmpty();
});

it('cuts over, routes by month, prunes queries, then rolls back', function () {
    $oldMonth = Carbon::today()->subMonths(2)->startOfMonth()->addDays(3)->toDateString();
    $today = Carbon::today()->toDateString();

    seedMonth($this, $oldMonth, 3);
    $live = $this->service->postManualCharge($this->folio, 'restaurant', 'Live', 5000, null, $this->user->id);
    seedMonth($this, $today, 2);

    $charge = PosCharge::factory()->create([
        'branch_id' => $this->branch->id,
        'folio_id' => $this->folio->id,
        'status' => 'posted',
        'total' => 2500,
        'transaction_id' => $live->id,
        'business_date' => $live->fresh()->business_date->toDateString(),
    ]);

    expect(Artisan::call('db:partition-transactions', ['--batch' => 2]))->toBe(0);

    // Routed into month partitions.
    $oldChild = DB::selectOne(
        "SELECT tableoid::regclass AS child FROM transactions WHERE description = 'Seed {$oldMonth} #1'"
    );
    $newChild = DB::selectOne(
        "SELECT tableoid::regclass AS child FROM transactions WHERE description = 'Live'"
    );

    expect((string) $oldChild->child)->toContain('transactions_')
        ->and((string) $newChild->child)->toContain('transactions_')
        ->and((string) $newChild->child)->not->toBe((string) $oldChild->child);

    // Planner prunes to the single relevant child.
    $plan = DB::select("EXPLAIN SELECT * FROM transactions WHERE business_date = '{$oldMonth}'");
    $text = implode("\n", array_map(fn ($r) => (string) array_values((array) $r)[0], $plan));

    expect($text)->toContain((string) $oldChild->child)
        ->and($text)->not->toContain((string) $newChild->child);

    // Composite FK enforced: mismatched date rejected. Runs inside a
    // savepoint so the expected violation does not poison the test.
    try {
        DB::transaction(fn () => PosCharge::factory()->create([
            'branch_id' => $this->branch->id,
            'folio_id' => $this->folio->id,
            'status' => 'posted',
            'total' => 100,
            'transaction_id' => $live->id,
            'business_date' => '2001-01-01',
        ]));
        $this->fail('Expected a foreign key violation.');
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain('pos_charges_transaction_composite');
    }

    // Linked child survives with matching date; writes still work.
    expect(PosCharge::find($charge->id)?->business_date)->not->toBeNull();

    $fresh = $this->service->postManualCharge($this->folio, 'minibar', 'After', 700, null, $this->user->id);

    expect($fresh->id)->toBeGreaterThan(0);

    $manager = new PartitionManager;
    expect($manager->readiness()['transactions']['partitioned'])->toBeTrue()
        ->and($manager->readiness()['transactions']['blockers'])->toBeEmpty();

    // Rollback restores the plain table and original FKs.
    expect(Artisan::call('db:partition-transactions', ['--rollback' => true]))->toBe(0);

    expect((new PartitionManager)->readiness()['transactions']['partitioned'])->toBeFalse();
    expect(Transaction::where('description', 'Live')->count())->toBe(1);

    $again = $this->service->postManualCharge($this->folio, 'spa', 'Rollback', 900, null, $this->user->id);

    expect($again->id)->toBeGreaterThan(0);
});
