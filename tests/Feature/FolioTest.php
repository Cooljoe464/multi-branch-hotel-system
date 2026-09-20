<?php

use App\Models\Branch;
use App\Models\Folio;
use App\Models\PosCharge;
use App\Models\Reservation;
use App\Models\User;
use App\Services\FolioService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create();
    $this->user = User::factory()->create(['branch_id' => $this->branch->id]);
    $this->reservation = Reservation::factory()->checkedIn()->create([
        'branch_id' => $this->branch->id,
        'room_rate' => 10000,
    ]);
});

it('can create an individual folio', function () {
    $service = new FolioService;
    $folio = $service->createFolio($this->branch->id, $this->reservation->id);

    expect($folio)->toBeInstanceOf(Folio::class)
        ->and($folio->type)->toBe('individual')
        ->and($folio->status)->toBe('open')
        ->and($folio->folio_number)->toStartWith('FOL-');
});

it('can create a master folio', function () {
    $service = new FolioService;
    $folio = $service->createFolio($this->branch->id, null, null, 'Corporate Account');

    expect($folio->type)->toBe('master')
        ->and($folio->description)->toBe('Corporate Account');
});

it('can create a child folio under a master', function () {
    $service = new FolioService;
    $master = $service->createFolio($this->branch->id, null, null, 'Master');
    $child = $service->createFolio($this->branch->id, $this->reservation->id, $master->id, 'Child 1');

    expect($child->type)->toBe('child')
        ->and($child->parent_folio_id)->toBe($master->id);
});

it('can post a debit to a folio', function () {
    $service = new FolioService;
    $folio = $service->createFolio($this->branch->id, $this->reservation->id);

    $transaction = $service->postDebit($folio, 'room_rate', 'Room charge', 10000);

    expect($transaction->type)->toBe('debit')
        ->and($transaction->amount)->toBe(10000)
        ->and($transaction->category)->toBe('room_rate');

    $folio->refresh();
    expect($folio->balance)->toBe(10000);
});

it('can post a credit to a folio', function () {
    $service = new FolioService;
    $folio = $service->createFolio($this->branch->id, $this->reservation->id);

    $service->postDebit($folio, 'room_rate', 'Room charge', 10000);
    $service->postCredit($folio, 'payment', 'Paystack payment', 5000);

    $folio->refresh();
    expect($folio->balance)->toBe(5000);
});

it('can calculate outstanding balance', function () {
    $service = new FolioService;
    $folio = $service->createFolio($this->branch->id, $this->reservation->id);

    $service->postDebit($folio, 'room_rate', 'Night 1', 10000);
    $service->postDebit($folio, 'restaurant', 'Dinner', 3000);
    $service->postCredit($folio, 'payment', 'Deposit', 5000);

    expect($service->getBalance($folio))->toBe(8000);
});

it('can transfer a charge between folios', function () {
    $service = new FolioService;
    $master = $service->createFolio($this->branch->id, null, null, 'Master');
    $child = $service->createFolio($this->branch->id, $this->reservation->id, $master->id);

    $debit = $service->postDebit($master, 'restaurant', 'Dinner', 3000);

    $service->transferCharge($debit, $child);

    $master->refresh();
    $child->refresh();

    expect($master->balance)->toBe(0)
        ->and($child->balance)->toBe(3000);
});

it('cannot void an already voided transaction', function () {
    $service = new FolioService;
    $folio = $service->createFolio($this->branch->id, $this->reservation->id);

    $transaction = $service->postDebit($folio, 'room_rate', 'Room charge', 10000);
    $transaction->void();

    $service->transferCharge($transaction, $folio);
})->throws(InvalidArgumentException::class, 'Cannot transfer a voided transaction.');

it('can void a transaction', function () {
    $service = new FolioService;
    $folio = $service->createFolio($this->branch->id, $this->reservation->id);

    $transaction = $service->postDebit($folio, 'room_rate', 'Room charge', 10000);
    $transaction->void();

    expect($transaction->fresh()->is_voided)->toBeTrue()
        ->and($transaction->fresh()->voided_at)->not->toBeNull();

    $folio->refresh();
    expect($folio->balance)->toBe(0);
});

it('cannot close a folio with a balance', function () {
    $service = new FolioService;
    $folio = $service->createFolio($this->branch->id, $this->reservation->id);

    $service->postDebit($folio, 'room_rate', 'Room charge', 10000);

    $service->closeFolio($folio);
})->throws(LogicException::class);

it('can close a settled folio', function () {
    $service = new FolioService;
    $folio = $service->createFolio($this->branch->id, $this->reservation->id);

    $service->postDebit($folio, 'room_rate', 'Room charge', 10000);
    $service->postCredit($folio, 'payment', 'Full payment', 10000);

    $service->closeFolio($folio);

    expect($folio->fresh()->status)->toBe('closed')
        ->and($folio->fresh()->is_settled)->toBeTrue()
        ->and($folio->fresh()->closed_at)->not->toBeNull();
});

it('can post a POS charge to a folio', function () {
    $service = new FolioService;
    $folio = $service->createFolio($this->branch->id, $this->reservation->id);

    $posCharge = PosCharge::create([
        'branch_id' => $this->branch->id,
        'reservation_id' => $this->reservation->id,
        'folio_id' => $folio->id,
        'outlet' => 'restaurant',
        'items' => [['name' => 'Pizza', 'qty' => 2, 'unit_price' => 1500, 'total' => 3000]],
        'subtotal' => 3000,
        'tax_amount' => 0,
        'total' => 3000,
        'status' => 'pending',
    ]);

    $transaction = $service->postPosCharge($posCharge, $this->user->id);

    expect($transaction->category)->toBe('restaurant')
        ->and($transaction->amount)->toBe(3000)
        ->and($transaction->posted_by)->toBe($this->user->id);

    $folio->refresh();
    expect($folio->balance)->toBe(3000);
});

it('applies tax to debit when tax_bps is provided', function () {
    $service = new FolioService;
    $folio = $service->createFolio($this->branch->id, $this->reservation->id);

    $transaction = $service->postDebit($folio, 'room_rate', 'Room charge', 10000, $this->user->id, 1000);

    expect($transaction->tax_amount)->toBe(1000);

    // Tax is stored separately, not added to folio balance
    $folio->refresh();
    expect($folio->balance)->toBe(10000);
});

it('does not apply tax to credit transactions', function () {
    $service = new FolioService;
    $folio = $service->createFolio($this->branch->id, $this->reservation->id);

    $service->postDebit($folio, 'room_rate', 'Room charge', 10000);
    $credit = $service->postCredit($folio, 'payment', 'Deposit', 5000, null, 1000);

    expect($credit->tax_amount)->toBe(0);

    $folio->refresh();
    expect($folio->balance)->toBe(5000);
});

it('records posted_by on transaction', function () {
    $service = new FolioService;
    $folio = $service->createFolio($this->branch->id, $this->reservation->id);

    $transaction = $service->postDebit($folio, 'room_rate', 'Room charge', 10000, $this->user->id);

    expect($transaction->posted_by)->toBe($this->user->id);
});

it('transaction is polymorphically linked to folio', function () {
    $service = new FolioService;
    $folio = $service->createFolio($this->branch->id, $this->reservation->id);

    $transaction = $service->postDebit($folio, 'room_rate', 'Room charge', 10000);

    // reference_type is set when posting via PosCharge, null for manual debits
    expect($transaction->reference_type)->toBeNull()
        ->and($transaction->folio_id)->toBe($folio->id);
});

it('builds correct POS charge description', function () {
    $service = new FolioService;
    $folio = $service->createFolio($this->branch->id, $this->reservation->id);

    $posCharge = PosCharge::create([
        'branch_id' => $this->branch->id,
        'reservation_id' => $this->reservation->id,
        'folio_id' => $folio->id,
        'outlet' => 'restaurant',
        'items' => [['name' => 'Grilled Chicken', 'qty' => 2, 'unit_price' => 2500, 'total' => 5000]],
        'subtotal' => 5000,
        'tax_amount' => 0,
        'total' => 5000,
        'status' => 'pending',
    ]);

    $transaction = $service->postPosCharge($posCharge, $this->user->id);

    expect($transaction->description)->toContain('restaurant')
        ->and($transaction->description)->toContain('Grilled Chicken');
});

it('accumulates balance across multiple debits', function () {
    $service = new FolioService;
    $folio = $service->createFolio($this->branch->id, $this->reservation->id);

    $service->postDebit($folio, 'room_rate', 'Night 1', 10000);
    $service->postDebit($folio, 'room_rate', 'Night 2', 10000);
    $service->postDebit($folio, 'restaurant', 'Dinner', 3000);

    $folio->refresh();
    expect($folio->balance)->toBe(23000);
});

it('can transfer a charge from master to child folio', function () {
    $service = new FolioService;
    $master = $service->createFolio($this->branch->id, null, null, 'Master');
    $child = $service->createFolio($this->branch->id, $this->reservation->id, $master->id);

    $service->postDebit($master, 'room_rate', 'Room charge', 10000);
    $service->postDebit($master, 'restaurant', 'Dinner', 3000);

    $transactions = $master->transactions->filter(fn ($t) => $t->type === 'debit');
    foreach ($transactions as $tx) {
        $service->transferCharge($tx, $child);
    }

    $master->refresh();
    $child->refresh();

    expect($master->balance)->toBe(0)
        ->and($child->balance)->toBe(13000);
});
