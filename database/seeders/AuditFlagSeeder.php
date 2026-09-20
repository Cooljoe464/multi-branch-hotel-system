<?php

namespace Database\Seeders;

use App\Models\AuditFlag;
use App\Models\Branch;
use App\Models\Transaction;
use App\Models\YieldRule;
use Illuminate\Database\Seeder;

class AuditFlagSeeder extends Seeder
{
    public function run(): void
    {
        $branches = Branch::all();

        foreach ($branches as $branch) {
            $this->createRateOverrideFlags($branch);
            $this->createVoidedTransactionFlags($branch);
            $this->createRefundFlags($branch);
        }
    }

    private function createRateOverrideFlags(Branch $branch): void
    {
        $rules = YieldRule::where('branch_id', $branch->id)
            ->where('min_occupancy_pct', '<=', 100)
            ->whereRaw('(100.0 - max_occupancy_pct) / 100.0 * 100 > 30')
            ->take(3)
            ->get();

        foreach ($rules as $rule) {
            $discountPct = (int) round((100.0 - (int) $rule->max_occupancy_pct) / 100.0 * 100);

            AuditFlag::create([
                'branch_id' => $branch->id,
                'flag_type' => 'rate_override_anomaly',
                'severity' => 'medium',
                'subject_type' => YieldRule::class,
                'subject_id' => $rule->id,
                'description' => "Rule (min: {$rule->min_occupancy_pct}%, max: {$rule->max_occupancy_pct}%) applies a {$discountPct}% markup exceeding 30% threshold.",
                'evidence' => [
                    'min_occupancy' => $rule->min_occupancy_pct,
                    'max_occupancy' => $rule->max_occupancy_pct,
                    'markup_pct' => $discountPct,
                    'multiplier' => $rule->rate_multiplier,
                ],
            ]);
        }
    }

    private function createVoidedTransactionFlags(Branch $branch): void
    {
        $voidedTxns = Transaction::whereHas('folio', fn ($q) => $q->where('branch_id', $branch->id))
            ->where('is_voided', true)
            ->where('amount', '>=', 10000)
            ->take(2)
            ->get();

        foreach ($voidedTxns as $txn) {
            AuditFlag::create([
                'branch_id' => $branch->id,
                'flag_type' => 'voided_charge',
                'severity' => 'high',
                'subject_type' => Transaction::class,
                'subject_id' => $txn->id,
                'description' => 'Txn #'.$txn->id.' voided for amount $'.number_format($txn->amount / 100, 2).'.',
                'evidence' => [
                    'amount' => $txn->amount,
                    'category' => $txn->category,
                    'voided_at' => $txn->voided_at?->toDateTimeString(),
                ],
            ]);
        }
    }

    private function createRefundFlags(Branch $branch): void
    {
        $refunds = Transaction::whereHas('folio', fn ($q) => $q->where('branch_id', $branch->id))
            ->where('type', 'refund')
            ->where('amount', '>=', 50000)
            ->take(2)
            ->get();

        foreach ($refunds as $refund) {
            AuditFlag::create([
                'branch_id' => $branch->id,
                'flag_type' => 'cash_drawer',
                'severity' => 'high',
                'subject_type' => Transaction::class,
                'subject_id' => $refund->id,
                'description' => 'Large refund #'.$refund->id.' for $'.number_format(abs($refund->amount) / 100, 2).'.',
                'evidence' => [
                    'amount' => abs($refund->amount),
                    'category' => $refund->category,
                ],
            ]);
        }
    }
}
