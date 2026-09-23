<?php

namespace Database\Seeders;

use App\Models\ChartAccount;
use App\Models\PostingRule;
use Illuminate\Database\Seeder;

/**
 * Standard hotel chart of accounts plus the posting rules for live
 * journal events. Idempotent: safe to re-run on every deploy.
 *
 * The GUEST_LEDGER / REVENUE / CASH codes are the interim Phase 0 legs;
 * Phase 2 remaps charge categories onto the numeric revenue accounts.
 */
class ChartSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->accounts() as [$code, $name, $type]) {
            ChartAccount::updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'type' => $type, 'system' => true],
            );
        }

        foreach ($this->rules() as [$event, $debit, $credit]) {
            PostingRule::updateOrCreate(
                ['event' => $event],
                ['debit_account' => $debit, 'credit_account' => $credit],
            );
        }
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function accounts(): array
    {
        return [
            ['1000', 'Cash on Hand', ChartAccount::TYPE_ASSET],
            ['1100', 'Bank', ChartAccount::TYPE_ASSET],
            ['1200', 'Guest Ledger Receivable', ChartAccount::TYPE_ASSET],
            ['1300', 'City Ledger Receivable', ChartAccount::TYPE_ASSET],
            ['1400', 'Inventory', ChartAccount::TYPE_ASSET],
            ['1500', 'Prepayments', ChartAccount::TYPE_ASSET],
            ['2000', 'Commission Payable', ChartAccount::TYPE_LIABILITY],
            ['2100', 'VAT Payable', ChartAccount::TYPE_LIABILITY],
            ['2200', 'Service Charge Payable', ChartAccount::TYPE_LIABILITY],
            ['2300', 'Deposits Held', ChartAccount::TYPE_LIABILITY],
            ['2400', 'Goods Received Not Invoiced', ChartAccount::TYPE_LIABILITY],
            ['3000', 'Opening Equity', ChartAccount::TYPE_EQUITY],
            ['4000', 'Room Revenue', ChartAccount::TYPE_REVENUE],
            ['4100', 'F&B Revenue', ChartAccount::TYPE_REVENUE],
            ['4200', 'Other Operating Revenue', ChartAccount::TYPE_REVENUE],
            ['5000', 'Cost of Sales F&B', ChartAccount::TYPE_EXPENSE],
            ['5100', 'Commission Expense', ChartAccount::TYPE_EXPENSE],
            ['5200', 'Operating Expenses', ChartAccount::TYPE_EXPENSE],
            ['GUEST_LEDGER', 'Guest Ledger (interim)', ChartAccount::TYPE_ASSET],
            ['REVENUE', 'Revenue (interim)', ChartAccount::TYPE_REVENUE],
            ['CASH', 'Cash (interim)', ChartAccount::TYPE_ASSET],
        ];
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function rules(): array
    {
        return [
            ['charge.posted', 'GUEST_LEDGER', 'REVENUE'],
            ['room_charge.posted', 'GUEST_LEDGER', 'REVENUE'],
            ['no_show.fee', 'GUEST_LEDGER', '4000'],
            ['cancellation.fee', 'GUEST_LEDGER', '4000'],
            ['day_use.charge', 'GUEST_LEDGER', '4000'],
            ['payment.received', 'CASH', 'GUEST_LEDGER'],
            ['refund.issued', 'REVENUE', 'CASH'],
            ['void.reversal', 'REVENUE', 'GUEST_LEDGER'],
            ['deposit.received', 'CASH', '2300'],
            ['commission.accrued', '5100', '2000'],
            ['commission.paid', '2000', '1100'],
            ['cogs.recognized', '5000', '1400'],
            ['inventory.received', '1400', '2400'],
            ['maintenance.parts', '5200', '1400'],
            ['loyalty.redeemed', 'REVENUE', 'GUEST_LEDGER'],
        ];
    }
}
