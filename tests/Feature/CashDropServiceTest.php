<?php

use App\Models\Branch;
use App\Models\Folio;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashierShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new CashierShiftService;
    $this->branch = Branch::factory()->create();
    $this->cashier = User::factory()->create();
});

it('records a mid-shift cash drop', function () {
    $shift = $this->service->open($this->branch, $this->cashier, 10000, now()->toDateString());
    $witness = User::factory()->create();

    $drop = $this->service->recordDrop($shift, 50000, 'ENV-0001', $witness, 'Safe drop');

    expect($drop->amount_minor)->toBe(50000)
        ->and($drop->envelope_number)->toBe('ENV-0001')
        ->and($drop->witness_user_id)->toBe($witness->id)
        ->and($drop->cashier_shift_id)->toBe($shift->id);
});

it('rejects drop on closed shift', function () {
    $shift = $this->service->open($this->branch, $this->cashier, 10000, now()->toDateString());
    $this->service->close($shift, 10000);

    $this->service->recordDrop($shift->fresh(), 5000, 'ENV-0002');
})->throws(LogicException::class, 'Cash drops can only be recorded on an open shift.');

it('performs blind close accounting for drops', function () {
    $shift = $this->service->open($this->branch, $this->cashier, 10000, now()->toDateString());

    // Simulate a cash payment of 50000
    $folio = Folio::factory()->create(['branch_id' => $this->branch->id]);

    Transaction::factory()->create([
        'folio_id' => $folio->id,
        'cashier_shift_id' => $shift->id,
        'type' => 'credit',
        'category' => 'payment',
        'description' => 'Cash payment - Room 101',
        'amount' => 50000,
        'is_voided' => false,
    ]);

    // Record a drop of 20000
    $this->service->recordDrop($shift, 20000, 'ENV-0003');

    // Blind close: expected = 10000 (float) + 50000 (payment) - 20000 (drop) = 40000
    $closed = $this->service->blindClose($shift, 40000);

    expect($closed->expected_cash_minor)->toBe(40000)
        ->and($closed->counted_cash_minor)->toBe(40000)
        ->and($closed->variance_minor)->toBe(0)
        ->and($closed->status)->toBe('closed');
});

it('requires note for large variance on blind close', function () {
    $shift = $this->service->open($this->branch, $this->cashier, 10000, now()->toDateString());

    // Blind close with big variance (count 100000 vs expected 10000)
    $this->service->blindClose($shift, 100000);
})->throws(LogicException::class, 'A note is required for variances above threshold.');
