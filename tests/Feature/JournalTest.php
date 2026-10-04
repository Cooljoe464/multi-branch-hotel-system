<?php

use App\Jobs\BackfillJournalJob;
use App\Models\Branch;
use App\Models\Folio;
use App\Models\JournalEntry;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Transaction;
use App\Services\BusinessDateService;
use App\Services\FolioService;
use App\Services\JournalService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create();
    $this->user = $this->makeAdminUser($this->branch);
    app(BusinessDateService::class)->current($this->branch);
});

it('journals every folio posting with balanced legs', function () {
    $folio = (new FolioService)->createStaffFolio($this->branch->id, 'Journal Guest');

    (new FolioService)->postDebit($folio, 'misc', 'Mini bar', 5000, $this->user->id);
    (new FolioService)->postCredit($folio, 'payment', 'Cash payment received', 5000, $this->user->id);

    $entries = JournalEntry::forBranch($this->branch->id)->orderBy('id')->get();

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->event)->toBe('charge.posted')
        ->and($entries[0]->debit_account)->toBe('GUEST_LEDGER')
        ->and($entries[0]->credit_account)->toBe('REVENUE')
        ->and($entries[0]->amount_minor)->toBe(5000)
        ->and($entries[1]->event)->toBe('payment.received');

    // Trial-balance identity: every entry balances by construction.
    $debits = (int) JournalEntry::forBranch($this->branch->id)->sum('amount_minor');
    expect($debits)->toBe(10000);
});

it('journals a posting exactly once under retry', function () {
    $folio = (new FolioService)->createStaffFolio($this->branch->id, 'Retry Guest');
    $service = new FolioService;

    $tx = $service->postDebit($folio, 'misc', 'Once', 2500, $this->user->id);

    // Simulate a retried worker re-journaling the same transaction.
    (new JournalService)->post(
        branch: $this->branch,
        businessDate: $tx->created_at->toDateString(),
        event: 'charge.posted',
        debitAccount: 'GUEST_LEDGER',
        creditAccount: 'REVENUE',
        amountMinor: $tx->amount,
        source: $tx,
        idempotency: ['scope' => 'folio.post', 'key' => "transactions:{$tx->id}"],
    );

    expect(JournalEntry::forBranch($this->branch->id)->count())->toBe(1);
});

it('refuses to update or delete journal rows at the database level', function () {
    $entry = JournalEntry::factory()->create(['branch_id' => $this->branch->id]);

    // Each attempt runs in a savepoint so the trigger's abort does not
    // poison the test's outer transaction.
    try {
        DB::transaction(fn () => $entry->update(['amount_minor' => 1]));
        $this->fail('Expected the append-only trigger to reject the update.');
    } catch (QueryException) {
    }

    try {
        DB::transaction(fn () => $entry->delete());
        $this->fail('Expected the append-only trigger to reject the delete.');
    } catch (QueryException) {
    }

    expect($entry->fresh()->amount_minor)->not->toBe(1);
});

it('rejects zero and negative journal amounts', function () {
    expect(fn () => (new JournalService)->post(
        branch: $this->branch,
        businessDate: now()->toDateString(),
        event: 'charge.posted',
        debitAccount: 'GUEST_LEDGER',
        creditAccount: 'REVENUE',
        amountMinor: 0,
    ))->toThrow(InvalidArgumentException::class);
});

it('backfills legacy postings and re-runs to zero new rows', function () {
    $roomType = RoomType::factory()->create(['branch_id' => $this->branch->id]);
    $room = Room::factory()->create([
        'branch_id' => $this->branch->id,
        'room_type_id' => $roomType->id,
    ]);
    $reservation = Reservation::factory()->create([
        'branch_id' => $this->branch->id,
        'room_id' => $room->id,
        'room_type_id' => $roomType->id,
    ]);
    $folio = Folio::where('reservation_id', $reservation->id)->first()
        ?? (new FolioService)->createFolio($this->branch->id, $reservation->id);

    // Legacy-style rows predate the journal wiring: insert without events.
    $legacy = Transaction::withoutEvents(function () use ($folio) {
        return Transaction::create([
            'folio_id' => $folio->id,
            'business_date' => now()->toDateString(),
            'currency_code' => 'NGN',
            'type' => 'debit',
            'category' => 'room_rate',
            'description' => 'Legacy charge',
            'amount' => 12000,
            'is_taxable' => false,
            'tax_amount' => 0,
        ]);
    });

    $first = app(BackfillJournalJob::class)->handle(app(JournalService::class), app(BusinessDateService::class));
    $second = app(BackfillJournalJob::class)->handle(app(JournalService::class), app(BusinessDateService::class));

    // One journal row per legacy transaction; second run adds nothing new
    // beyond re-counting (idempotent keys suppress duplicates).
    expect(JournalEntry::where('idempotency_scope', 'journal.backfill')
        ->where('idempotency_key', "transactions:{$legacy->id}")
        ->count())->toBe(1);

    $txSum = (int) Transaction::where('is_voided', false)
        ->whereHas('folio', fn ($q) => $q->where('branch_id', $this->branch->id))
        ->sum('amount');
    $journalSum = (int) JournalEntry::forBranch($this->branch->id)
        ->where('event', 'charge.posted')
        ->sum('amount_minor');

    expect($journalSum)->toBe($txSum);
    expect($first['entries'])->toBeGreaterThanOrEqual(1);
});

it('serves the journal index to permitted roles only', function () {
    $this->withoutVite();

    $this->actingAs($this->user)
        ->get("/branches/{$this->branch->id}/journal")
        ->assertOk();

    $user = $this->makeAdminUser($this->branch);
    $user->removeRole('Global Admin');

    $this->actingAs($user)
        ->get("/branches/{$this->branch->id}/journal")
        ->assertForbidden();
});
