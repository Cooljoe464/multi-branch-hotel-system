<?php

namespace Tests\Unit\Models;

use App\Models\Folio;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_void_marks_transaction_as_voided(): void
    {
        $folio = Folio::factory()->create(['balance' => 0]);
        $tx = Transaction::create([
            'folio_id' => $folio->id,
            'type' => 'debit',
            'category' => 'room_rate',
            'amount' => 10000,
            'description' => 'Night 1',
        ]);

        $result = $tx->void();

        $this->assertTrue($result);
        $this->assertTrue($tx->fresh()->is_voided);
        $this->assertNotNull($tx->fresh()->voided_at);
    }

    public function test_void_updates_folio_balance(): void
    {
        $folio = Folio::factory()->create(['balance' => 0]);
        $tx = Transaction::create([
            'folio_id' => $folio->id,
            'type' => 'debit',
            'category' => 'room_rate',
            'amount' => 10000,
            'description' => 'Night 1',
        ]);

        $tx->void();

        $folio->refresh();
        $this->assertEquals(0, $folio->balance);
    }

    public function test_void_returns_false_for_already_voided(): void
    {
        $folio = Folio::factory()->create(['balance' => 0]);
        $tx = Transaction::create([
            'folio_id' => $folio->id,
            'type' => 'debit',
            'category' => 'room_rate',
            'amount' => 10000,
            'description' => 'Night 1',
        ]);

        $tx->void();
        $result = $tx->void();

        $this->assertFalse($result);
    }

    public function test_scope_debits_filters_debit_transactions(): void
    {
        $folio = Folio::factory()->create(['balance' => 0]);

        Transaction::create([
            'folio_id' => $folio->id,
            'type' => 'debit',
            'category' => 'room_rate',
            'amount' => 10000,
            'description' => 'Night 1',
        ]);

        Transaction::create([
            'folio_id' => $folio->id,
            'type' => 'credit',
            'category' => 'payment',
            'amount' => 5000,
            'description' => 'Deposit',
        ]);

        $this->assertEquals(1, Transaction::debits()->count());
    }

    public function test_scope_credits_filters_credit_transactions(): void
    {
        $folio = Folio::factory()->create(['balance' => 0]);

        Transaction::create([
            'folio_id' => $folio->id,
            'type' => 'debit',
            'category' => 'room_rate',
            'amount' => 10000,
            'description' => 'Night 1',
        ]);

        Transaction::create([
            'folio_id' => $folio->id,
            'type' => 'credit',
            'category' => 'payment',
            'amount' => 5000,
            'description' => 'Deposit',
        ]);

        $this->assertEquals(1, Transaction::credits()->count());
    }

    public function test_scope_not_voided_excludes_voided(): void
    {
        $folio = Folio::factory()->create(['balance' => 0]);

        $tx1 = Transaction::create([
            'folio_id' => $folio->id,
            'type' => 'debit',
            'category' => 'room_rate',
            'amount' => 10000,
            'description' => 'Night 1',
        ]);

        Transaction::create([
            'folio_id' => $folio->id,
            'type' => 'debit',
            'category' => 'restaurant',
            'amount' => 3000,
            'description' => 'Dinner',
        ]);

        $tx1->void();

        $this->assertEquals(1, Transaction::notVoided()->count());
    }

    public function test_scope_for_folio_filters_correctly(): void
    {
        $folio1 = Folio::factory()->create(['balance' => 0]);
        $folio2 = Folio::factory()->create(['balance' => 0]);

        Transaction::create([
            'folio_id' => $folio1->id,
            'type' => 'debit',
            'category' => 'room_rate',
            'amount' => 10000,
            'description' => 'Night 1',
        ]);

        Transaction::create([
            'folio_id' => $folio2->id,
            'type' => 'debit',
            'category' => 'restaurant',
            'amount' => 3000,
            'description' => 'Dinner',
        ]);

        $this->assertEquals(1, Transaction::forFolio($folio1->id)->count());
        $this->assertEquals(1, Transaction::forFolio($folio2->id)->count());
    }

    public function test_transaction_belongs_to_folio(): void
    {
        $folio = Folio::factory()->create(['balance' => 0]);
        $tx = Transaction::create([
            'folio_id' => $folio->id,
            'type' => 'debit',
            'category' => 'room_rate',
            'amount' => 10000,
            'description' => 'Night 1',
        ]);

        $this->assertInstanceOf(Folio::class, $tx->folio);
        $this->assertEquals($folio->id, $tx->folio->id);
    }
}
