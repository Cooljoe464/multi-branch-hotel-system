<?php

use App\Jobs\BackfillTrialBalancesJob;
use App\Models\Branch;
use App\Models\BusinessDate;
use App\Models\ChartAccount;
use App\Models\TrialBalance;
use App\Services\BusinessDateService;
use App\Services\FolioService;
use App\Services\PostingService;
use App\Services\TrialBalanceService;
use Database\Seeders\ChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create();
    $this->user = $this->makeAdminUser($this->branch);
    $this->seed(ChartSeeder::class);
    app(BusinessDateService::class)->current($this->branch);
});

it('posts only through valid chart accounts', function () {
    $service = app(PostingService::class);

    $entry = $service->post(
        $this->branch, now()->toDateString(), 'charge.posted', 5000,
        null, ['scope' => 'test', 'key' => 'acc-1'], $this->user,
    );

    expect($entry->debit_account)->toBe('GUEST_LEDGER')
        ->and($entry->credit_account)->toBe('REVENUE');

    expect(fn () => $service->post(
        $this->branch, now()->toDateString(), 'no.such.event', 5000,
    ))->toThrow(RuntimeException::class, 'No posting rule');
});

it('closes a balanced day', function () {
    $folio = (new FolioService)->createStaffFolio($this->branch->id, 'Ledger Guest');
    (new FolioService)->postDebit($folio, 'misc', 'Charge', 8000, $this->user->id);
    (new FolioService)->recordPayment($folio, 8000, 'cash', $this->user->id);

    $balance = app(TrialBalanceService::class)->close(
        $this->branch,
        BusinessDate::forBranch($this->branch->id)->open()->firstOrFail()->business_date->toDateString()
    );

    expect($balance->balanced)->toBeTrue()
        ->and($balance->totals['debits'])->toBe($balance->totals['credits'])
        ->and($balance->totals['debits'])->toBe(16000);

    // Re-closing is idempotent.
    $again = app(TrialBalanceService::class)->close($this->branch, $balance->business_date->toDateString());
    expect($again->id)->toBe($balance->id);
});

it('flags a day whose accounts left the chart', function () {
    $folio = (new FolioService)->createStaffFolio($this->branch->id, 'Drift Guest');
    (new FolioService)->postDebit($folio, 'misc', 'Charge', 3000, $this->user->id);

    // Simulate chart drift: retire an account the day already posted to.
    ChartAccount::where('code', 'REVENUE')->delete();

    $balance = app(TrialBalanceService::class)->close(
        $this->branch,
        BusinessDate::forBranch($this->branch->id)->open()->firstOrFail()->business_date->toDateString()
    );

    expect($balance->balanced)->toBeFalse()
        ->and($balance->totals['unknown_accounts'])->toContain('REVENUE');
});

it('backfills trial rows for historic journal dates', function () {
    $folio = (new FolioService)->createStaffFolio($this->branch->id, 'History Guest');
    (new FolioService)->postDebit($folio, 'misc', 'Charge', 2000, $this->user->id);

    $stats = app(BackfillTrialBalancesJob::class)->handle();

    expect($stats['days'])->toBeGreaterThanOrEqual(1);
    expect(TrialBalance::forBranch($this->branch->id)->count())->toBe($stats['days']);

    $rerun = app(BackfillTrialBalancesJob::class)->handle();
    expect(TrialBalance::forBranch($this->branch->id)->count())->toBe($stats['days']);
    expect($rerun['days'])->toBe($stats['days']);
});

it('serves chart and trial pages to permitted roles', function () {
    $this->withoutVite();

    $this->actingAs($this->user)->get("/branches/{$this->branch->id}/chart")->assertOk();
    $this->actingAs($this->user)->get("/branches/{$this->branch->id}/trial-balance")->assertOk();

    $user = $this->makeAdminUser($this->branch);
    $user->removeRole('Global Admin');

    $this->actingAs($user)->get("/branches/{$this->branch->id}/chart")->assertForbidden();
});
