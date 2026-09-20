<?php

namespace Tests\Unit\Models;

use App\Models\Branch;
use App\Models\Folio;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FolioTest extends TestCase
{
    use RefreshDatabase;

    public function test_folio_number_is_generated_on_creation(): void
    {
        $folio = Folio::factory()->create([
            'folio_number' => null,
        ]);

        $this->assertNotEmpty($folio->folio_number);
        $this->assertStringStartsWith('FOL-', $folio->folio_number);
    }

    public function test_folio_number_format_includes_date(): void
    {
        $folio = Folio::factory()->create([
            'folio_number' => null,
        ]);

        $today = now()->format('Ymd');
        $this->assertStringContainsString($today, $folio->folio_number);
    }

    public function test_folio_number_is_unique(): void
    {
        $folios = Folio::factory()->count(5)->create();

        $numbers = $folios->pluck('folio_number')->unique();
        $this->assertCount(5, $numbers);
    }

    public function test_debits_total_sums_debit_transactions(): void
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
            'type' => 'debit',
            'category' => 'restaurant',
            'amount' => 3000,
            'description' => 'Dinner',
        ]);

        $this->assertEquals(13000, $folio->debits_total);
    }

    public function test_credits_total_sums_credit_transactions(): void
    {
        $folio = Folio::factory()->create(['balance' => 0]);

        Transaction::create([
            'folio_id' => $folio->id,
            'type' => 'credit',
            'category' => 'payment',
            'amount' => 5000,
            'description' => 'Deposit',
        ]);

        Transaction::create([
            'folio_id' => $folio->id,
            'type' => 'credit',
            'category' => 'payment',
            'amount' => 3000,
            'description' => 'Partial payment',
        ]);

        $this->assertEquals(8000, $folio->credits_total);
    }

    public function test_outstanding_balance_calculates_correctly(): void
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

        $this->assertEquals(5000, $folio->outstanding_balance);
    }

    public function test_outstanding_balance_excludes_voided_transactions(): void
    {
        $branch = Branch::factory()->create();
        $folio = Folio::factory()->create(['balance' => 0, 'branch_id' => $branch->id]);

        $tx = Transaction::create([
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
            'amount' => 10000,
            'description' => 'Full payment',
        ]);

        $tx->void();

        // Debit is voided, credit remains: outstanding = 0 - 10000 = -10000
        // But the folio balance column is updated to outstanding_balance
        $folio->refresh();
        $this->assertEquals(-10000, $folio->outstanding_balance);
        $this->assertEquals(-10000, $folio->balance);
    }

    public function test_scope_open_filters_correctly(): void
    {
        Folio::factory()->open()->count(3)->create();
        Folio::factory()->closed()->count(2)->create();

        $this->assertEquals(3, Folio::open()->count());
    }

    public function test_scope_for_branch_filters_correctly(): void
    {
        $branch1 = Branch::factory()->create();
        $branch2 = Branch::factory()->create();
        Folio::factory()->count(3)->create(['branch_id' => $branch1->id]);
        Folio::factory()->count(2)->create(['branch_id' => $branch2->id]);

        $this->assertEquals(3, Folio::forBranch($branch1->id)->count());
        $this->assertEquals(2, Folio::forBranch($branch2->id)->count());
    }
}
