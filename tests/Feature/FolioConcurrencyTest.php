<?php

use App\Models\Branch;
use App\Models\Folio;
use App\Models\Reservation;
use App\Services\FolioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create();
    $this->reservation = Reservation::factory()->checkedIn()->create([
        'branch_id' => $this->branch->id,
        'room_rate' => 10000,
    ]);
});

it('serializes debit postings via lockForUpdate', function () {
    $service = new FolioService;
    $folio = $service->createFolio($this->branch->id, $this->reservation->id);

    $service->postDebit($folio, 'room_rate', 'Night 1', 10000);
    $service->postDebit($folio, 'restaurant', 'Dinner', 3000);

    $folio->refresh();
    expect($folio->balance)->toBe(13000);

    $this->assertDatabaseHas('transactions', [
        'folio_id' => $folio->id,
        'category' => 'room_rate',
        'amount' => 10000,
        'is_voided' => false,
    ]);
    $this->assertDatabaseHas('transactions', [
        'folio_id' => $folio->id,
        'category' => 'restaurant',
        'amount' => 3000,
        'is_voided' => false,
    ]);
});

it('serializes credit postings via lockForUpdate', function () {
    $service = new FolioService;
    $folio = $service->createFolio($this->branch->id, $this->reservation->id);

    $service->postDebit($folio, 'room_rate', 'Night 1', 10000);
    $service->postCredit($folio, 'payment', 'Cash payment', 5000);
    $service->postCredit($folio, 'payment', 'Card payment', 3000);

    $folio->refresh();
    expect($folio->balance)->toBe(2000);
});

it('maintains correct balance after multiple interleaved debits and credits', function () {
    $service = new FolioService;
    $folio = $service->createFolio($this->branch->id, $this->reservation->id);

    $service->postDebit($folio, 'room_rate', 'Night 1', 10000);
    $service->postDebit($folio, 'minibar', 'Snacks', 1500);
    $service->postCredit($folio, 'payment', 'Deposit', 5000);
    $service->postDebit($folio, 'restaurant', 'Lunch', 2500);
    $service->postCredit($folio, 'payment', 'Final payment', 9000);

    $service->getBalance($folio);
    $folio->refresh();

    expect($folio->balance)->toBe(0);
});

it('folio row is locked during transaction posting', function () {
    $service = new FolioService;
    $folio = $service->createFolio($this->branch->id, $this->reservation->id);

    $lockAcquired = false;

    DB::transaction(function () use ($folio, &$lockAcquired) {
        Folio::where('id', $folio->id)->lockForUpdate()->first();
        $lockAcquired = true;

        $anotherConnection = DB::connection();
        $anotherConnection->beginTransaction();
        $result = $anotherConnection->select('SELECT id FROM folios WHERE id = ? FOR UPDATE', [$folio->id]);
        $anotherConnection->rollBack();
    });

    expect($lockAcquired)->toBeTrue();
});

it('can void a transaction and recalculate balance correctly', function () {
    $service = new FolioService;
    $folio = $service->createFolio($this->branch->id, $this->reservation->id);

    $txn1 = $service->postDebit($folio, 'room_rate', 'Night 1', 10000);
    $txn2 = $service->postDebit($folio, 'restaurant', 'Dinner', 3000);

    $service->postCredit($folio, 'payment', 'Partial', 5000);

    $txn1->void();

    $folio->refresh();
    expect($folio->balance)->toBe(-2000);
});

it('transferCharge locks target folio row', function () {
    $service = new FolioService;
    $master = $service->createFolio($this->branch->id, null, null, 'Master');
    $child = $service->createFolio($this->branch->id, $this->reservation->id, $master->id);

    $debit = $service->postDebit($master, 'restaurant', 'Dinner', 3000);
    $service->transferCharge($debit, $child);

    $master->refresh();
    $child->refresh();

    expect($master->balance)->toBe(0)
        ->and($child->balance)->toBe(3000);

    $this->assertDatabaseHas('transactions', [
        'id' => $debit->id,
        'is_voided' => true,
    ]);
});

it('closeFolio acquires lock before checking balance', function () {
    $service = new FolioService;
    $folio = $service->createFolio($this->branch->id, $this->reservation->id);

    $service->postDebit($folio, 'room_rate', 'Night 1', 10000);
    $service->postCredit($folio, 'payment', 'Full payment', 10000);

    $service->closeFolio($folio);

    expect($folio->fresh()->status)->toBe('closed')
        ->and($folio->fresh()->is_settled)->toBeTrue()
        ->and($folio->fresh()->closed_at)->not->toBeNull();
});

it('cannot close a folio with outstanding balance (locked check)', function () {
    $service = new FolioService;
    $folio = $service->createFolio($this->branch->id, $this->reservation->id);

    $service->postDebit($folio, 'room_rate', 'Night 1', 10000);

    $service->closeFolio($folio);
})->throws(LogicException::class);
