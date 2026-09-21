<?php

use App\Exceptions\StaleModelException;
use App\Models\Branch;
use App\Models\FolioRoutingRule;
use App\Models\FolioWindow;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Services\BusinessDateService;
use App\Services\FolioService;
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

it('routes charges to windows by rule', function () {
    $folio = $this->service->createStaffFolio($this->branch->id, 'Routing Guest');

    $company = $this->service->createWindow($folio, 'company', 'company');

    FolioRoutingRule::create([
        'folio_id' => $folio->id,
        'charge_category' => 'room_rate',
        'target_window_id' => $company->id,
        'active' => true,
    ]);

    $roomTx = $this->service->postManualCharge($folio, 'room_rate', 'Room', 20000, null, $this->user->id);
    $barTx = $this->service->postManualCharge($folio, 'minibar', 'Bar', 3000, null, $this->user->id);

    expect($roomTx->folio_window_id)->toBe($company->id);
    expect($barTx->folio_window_id)->not->toBe($company->id);
    expect($barTx->window->code)->toBe(FolioWindow::CODE_ROOM);
});

it('splits a charge by percent with exact minor-unit sums', function () {
    $folio = $this->service->createStaffFolio($this->branch->id, 'Split Guest');

    $tx = $this->service->postManualCharge($folio, 'restaurant', 'Dinner 1001', 1001, null, $this->user->id);

    $splits = $this->service->splitTransaction($tx, [
        ['window_code' => 'room', 'percent_bps' => 3333],
        ['window_code' => 'incidentals', 'percent_bps' => 3333],
        ['window_code' => 'company', 'percent_bps' => 3334],
    ]);

    // floor each (333/333/333 = 999), remainder 2 to the first leg.
    expect(array_sum(array_column(array_map(fn ($s) => $s->toArray(), $splits), 'amount_minor')))->toBe(1001);
    expect($splits[0]->amount_minor)->toBe(335);
    expect(TransactionSplit::where('transaction_id', $tx->id)->count())->toBe(3);

    // Splits never touch the ledger: one journal row for the parent.
    expect(JournalEntry::where('idempotency_key', "transactions:{$tx->id}")->count())->toBe(1);

    // Re-splitting is rejected; mismatched sums are rejected.
    expect(fn () => $this->service->splitTransaction($tx, [
        ['window_code' => 'room', 'percent_bps' => 5000],
        ['window_code' => 'incidentals', 'percent_bps' => 5000],
    ]))->toThrow(LogicException::class);

    $tx2 = $this->service->postManualCharge($folio, 'restaurant', 'Lunch', 5000, null, $this->user->id);
    expect(fn () => $this->service->splitTransaction($tx2, [
        ['window_code' => 'room', 'amount_minor' => 2000],
        ['window_code' => 'incidentals', 'amount_minor' => 2000],
    ]))->toThrow(InvalidArgumentException::class, 'sum to the transaction amount');
});

it('transfers a charge to the master folio with reversal', function () {
    $folio = $this->service->createStaffFolio($this->branch->id, 'Child Guest');
    $master = $this->service->createStaffFolio($this->branch->id, 'Master');
    $master->update(['is_master' => true]);

    $tx = $this->service->postManualCharge($folio, 'minibar', 'Minibar', 4000, null, $this->user->id);

    $moved = $this->service->transferToMaster($tx, $master, $this->user->id);

    expect($moved->folio_id)->toBe($master->id)
        ->and($moved->transfer_of_transaction_id)->toBe($tx->id)
        ->and($tx->fresh()->is_voided)->toBeTrue();

    // Reversal journal exists for the void.
    expect(JournalEntry::where('idempotency_key', "transactions:{$tx->id}")
        ->where('event', 'void.reversal')
        ->count())->toBe(1);

    // Idempotent re-run returns the same moved transaction.
    $again = $this->service->transferToMaster($tx->fresh(), $master, $this->user->id);
    expect($again->id)->toBe($moved->id);
});

it('serves window endpoints under the new permissions', function () {
    $folio = $this->service->createStaffFolio($this->branch->id, 'Endpoint Guest');

    $this->actingAs($this->user)
        ->post("/folios/{$folio->id}/windows", ['code' => 'company', 'payer_type' => 'company'])
        ->assertRedirect();

    expect(FolioWindow::forFolio($folio->id)->where('code', 'company')->exists())->toBeTrue();

    $user = $this->makeAdminUser($this->branch);
    $user->removeRole('Global Admin');

    $this->actingAs($user)
        ->post("/folios/{$folio->id}/windows", ['code' => 'x', 'payer_type' => 'guest'])
        ->assertForbidden();
});

it('rejects stale-version transfers', function () {
    $folio = $this->service->createStaffFolio($this->branch->id, 'Stale Guest');

    $tx = $this->service->postManualCharge($folio, 'misc', 'Charge', 1000, null, $this->user->id, 1);

    // Version moved 1 -> 2 on post; reusing 1 must fail.
    expect(fn () => $this->service->postManualCharge($folio, 'misc', 'Late', 1000, null, $this->user->id, 1))
        ->toThrow(StaleModelException::class);

    expect(Transaction::where('folio_id', $folio->id)->where('is_voided', false)->count())->toBe(1);
});
