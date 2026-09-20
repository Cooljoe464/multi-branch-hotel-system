<?php

namespace Database\Seeders;

use App\Models\Folio;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class TransactionSeeder extends Seeder
{
    public function run(): void
    {
        $folios = Folio::all();
        $users = User::all();

        if ($folios->isEmpty()) {
            return;
        }

        foreach ($folios as $folio) {
            $this->createTransactionsForFolio($folio, $users);
        }
    }

    /** @param  Collection<int, User>  $users */
    private function createTransactionsForFolio(Folio $folio, Collection $users): void
    {
        $poster = $users->first();

        if (! $poster) {
            return;
        }

        $totalDebit = 0;
        $totalCredit = 0;

        $roomCharge = Transaction::firstOrCreate(
            [
                'folio_id' => $folio->id,
                'category' => 'room_rate',
                'type' => 'debit',
            ],
            [
                'description' => 'Room charge',
                'amount' => 35000,
                'is_taxable' => true,
                'tax_amount' => 2625,
                'posted_by' => $poster->id,
            ]
        );
        $totalDebit += 35000;

        $restaurantCharge = Transaction::firstOrCreate(
            [
                'folio_id' => $folio->id,
                'category' => 'restaurant',
                'type' => 'debit',
            ],
            [
                'description' => 'Restaurant charges',
                'amount' => 8500,
                'is_taxable' => true,
                'tax_amount' => 638,
                'posted_by' => $poster->id,
            ]
        );
        $totalDebit += 8500;

        if ($folio->status === 'open') {
            $minibarCharge = Transaction::firstOrCreate(
                [
                    'folio_id' => $folio->id,
                    'category' => 'minibar',
                    'type' => 'debit',
                ],
                [
                    'description' => 'Minibar usage',
                    'amount' => 2500,
                    'is_taxable' => true,
                    'tax_amount' => 188,
                    'posted_by' => $poster->id,
                ]
            );
            $totalDebit += 2500;
        }

        if ($folio->status === 'closed') {
            $payment = Transaction::firstOrCreate(
                [
                    'folio_id' => $folio->id,
                    'category' => 'payment',
                    'type' => 'credit',
                ],
                [
                    'description' => 'Payment received',
                    'amount' => $totalDebit,
                    'is_taxable' => false,
                    'tax_amount' => 0,
                    'posted_by' => $poster->id,
                ]
            );
            $totalCredit = $totalDebit;
        } elseif ($folio->reservation && $folio->reservation->amount_paid > 0) {
            $partialAmount = min($folio->reservation->amount_paid, $totalDebit);

            Transaction::firstOrCreate(
                [
                    'folio_id' => $folio->id,
                    'category' => 'payment',
                    'type' => 'credit',
                ],
                [
                    'description' => 'Partial payment received',
                    'amount' => $partialAmount,
                    'is_taxable' => false,
                    'tax_amount' => 0,
                    'posted_by' => $poster->id,
                ]
            );
            $totalCredit = $partialAmount;
        }

        $folio->update(['balance' => $totalDebit - $totalCredit]);
    }
}
