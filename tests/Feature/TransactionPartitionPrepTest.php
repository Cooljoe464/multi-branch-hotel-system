<?php

use App\Models\Branch;
use App\Models\PosCharge;
use App\Models\Transaction;
use App\Services\BusinessDateService;
use App\Services\FolioService;
use App\Services\PosService;
use Database\Seeders\ChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create();
    $this->user = $this->makeAdminUser($this->branch);
    $this->seed(ChartSeeder::class);
    app(BusinessDateService::class)->current($this->branch);
    $this->service = new FolioService;
});

it('stamps splits with the parent business date', function () {
    $folio = $this->service->createStaffFolio($this->branch->id, 'Split Guest');
    $tx = $this->service->postManualCharge($folio, 'restaurant', 'Dinner', 1000, null, $this->user->id);

    $splits = $this->service->splitTransaction($tx->fresh() ?? $tx, [
        ['window_code' => 'room', 'percent_bps' => 5000],
        ['window_code' => 'incidentals', 'percent_bps' => 5000],
    ]);

    foreach ($splits as $split) {
        expect($split->business_date->toDateString())->toBe($tx->fresh()->business_date->toDateString());
    }
});

it('stamps disputes with the transaction business date', function () {
    $folio = $this->service->createStaffFolio($this->branch->id, 'Dispute Guest');
    $tx = $this->service->postManualCharge($folio, 'minibar', 'Bar', 3000, null, $this->user->id);

    $dispute = $this->service->initiateDispute($folio, $tx->id, 'Wrong item', $this->user->id);

    expect($dispute->business_date->toDateString())->toBe($tx->fresh()->business_date->toDateString());
});

it('stamps posted pos charges with the transaction business date', function () {
    $folio = $this->service->createStaffFolio($this->branch->id, 'POS Guest');

    $charge = PosCharge::factory()->create([
        'branch_id' => $this->branch->id,
        'folio_id' => $folio->id,
        'status' => 'pending',
        'total' => 2500,
    ]);

    $transaction = (new PosService)->postTab($charge->fresh() ?? $charge, $this->user->id);

    $charge = $charge->fresh() ?? $charge;

    expect($charge->transaction_id)->toBe($transaction->id)
        ->and($charge->business_date->toDateString())->toBe($transaction->fresh()->business_date->toDateString());
});

it('has no null transaction business dates after migration', function () {
    expect(Transaction::whereNull('business_date')->count())->toBe(0);
});
