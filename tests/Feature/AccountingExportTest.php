<?php

use App\Exceptions\AvailabilityException;
use App\Jobs\ExportDailyJournalJob;
use App\Models\AccountingExport;
use App\Models\AccountingLink;
use App\Models\Branch;
use App\Models\ChartAccount;
use App\Services\Accounting\FakeAccountingDriver;
use App\Services\BusinessDateService;
use App\Services\FolioService;
use App\Services\TrialBalanceService;
use Database\Seeders\ChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    FakeAccountingDriver::reset();
    $this->branch = Branch::factory()->create(['timezone' => 'Africa/Lagos']);
    $this->user = $this->makeAdminUser($this->branch);
    $this->seed(ChartSeeder::class);
    $this->businessDate = app(BusinessDateService::class)->current($this->branch)->business_date->toDateString();

    $folio = (new FolioService)->createStaffFolio($this->branch->id, 'Export Guest');
    (new FolioService)->postDebit($folio, 'misc', 'Charge', 5000, $this->user->id);

    $this->link = AccountingLink::create([
        'branch_id' => $this->branch->id,
        'provider' => 'fake',
        'sandbox' => true,
        'is_active' => true,
    ]);
});

function mapEveryChart(AccountingLink $link): void
{
    $map = [];
    foreach (ChartAccount::orderBy('code')->pluck('code')->all() as $code) {
        $map[$code] = 'EXT-'.$code;
    }
    $link->update(['account_map' => $map]);
}

it('exports totals equal to the trial balance', function () {
    mapEveryChart($this->link);

    $expected = (new TrialBalanceService)->totals($this->branch, $this->businessDate);
    expect($expected['balanced'])->toBeTrue();

    $result = (new ExportDailyJournalJob($this->branch->id, $this->businessDate, 'fake'))->export();

    expect($result['duplicate'])->toBeFalse()
        ->and($result['export']->status)->toBe(AccountingExport::STATUS_DELIVERED)
        ->and($result['export']->totals['debits'])->toBe($expected['debits'])
        ->and($result['export']->totals['credits'])->toBe($expected['credits']);
});

it('re-runs the same date as a single external batch', function () {
    mapEveryChart($this->link);

    $job = new ExportDailyJournalJob($this->branch->id, $this->businessDate, 'fake');
    $first = $job->export();
    $second = $job->export();

    expect($second['duplicate'])->toBeTrue()
        ->and($second['export']->id)->toBe($first['export']->id)
        ->and(FakeAccountingDriver::batches())->toHaveCount(1)
        ->and(AccountingExport::count())->toBe(1);
});

it('refuses unbalanced days without sending', function () {
    mapEveryChart($this->link);

    ChartAccount::where('code', 'REVENUE')->delete();

    $result = (new ExportDailyJournalJob($this->branch->id, $this->businessDate, 'fake'))->export();

    expect($result['export']->status)->toBe(AccountingExport::STATUS_FAILED)
        ->and(FakeAccountingDriver::batches())->toBeEmpty();
});

it('reports the missing account code with 422', function () {
    $this->link->update(['account_map' => ['CASH' => 'EXT-CASH']]);

    try {
        (new ExportDailyJournalJob($this->branch->id, $this->businessDate, 'fake'))->export();
        $this->fail('Expected an ACCOUNT_MAP_MISSING exception.');
    } catch (AvailabilityException $e) {
        expect($e->availabilityCode)->toBe('ACCOUNT_MAP_MISSING');
    }
});
