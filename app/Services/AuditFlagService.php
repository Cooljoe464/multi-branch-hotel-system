<?php

namespace App\Services;

use App\Models\AuditFlag;
use App\Models\Branch;
use App\Models\Transaction;
use App\Models\YieldRule;
use App\Support\BranchTime;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AuditFlagService
{
    /**
     * Generate audit flags for daily night audit.
     *
     * @return list<array{flag_type: string, severity: string, description: string, subject_type?: class-string, subject_id?: int}>
     */
    public function generate(Branch $branch, null|string|Carbon $businessDate = null): array
    {
        $date = $businessDate instanceof Carbon ? $businessDate->toDateString() : ($businessDate ?? BranchTime::today($branch));

        $flags = [];
        $flags = array_merge($flags, $this->checkRateOverrides($branch, $date));
        $flags = array_merge($flags, $this->checkVoidedTransactions($branch, $date));
        $flags = array_merge($flags, $this->checkRefunds($branch, $date));

        $generated = 0;
        foreach ($flags as $flag) {
            AuditFlag::create([
                'branch_id' => $branch->id,
                'flag_type' => $flag['flag_type'],
                'severity' => $flag['severity'],
                'subject_type' => $flag['subject_type'] ?? null,
                'subject_id' => $flag['subject_id'] ?? null,
                'description' => $flag['description'],
                'evidence' => $flag['evidence'] ?? null,
            ]);
            $generated++;
        }

        Log::info("Generated {$generated} audit flags for branch {$branch->id} on {$date}");

        return $flags;
    }

    /**
     * @return list<array{flag_type: string, severity: string, description: string, subject_type?: class-string, subject_id?: int, evidence?: array<string, mixed>}>
     */
    private function checkRateOverrides(Branch $branch, string $businessDate): array
    {
        $settings = is_array($branch->settings) ? $branch->settings : [];
        $thresholdSetting = $settings['audit_rate_override_threshold'] ?? 30;
        $threshold = (int) (is_int($thresholdSetting) ? $thresholdSetting : 30);

        $rules = YieldRule::where('branch_id', $branch->id)
            ->where('min_occupancy_pct', '<=', 100)
            ->whereRaw('(100.0 - max_occupancy_pct) / 100.0 * 100 > ?', [$threshold])
            ->get();

        $flags = [];
        foreach ($rules as $rule) {
            $discountPct = (int) round((100.0 - (int) $rule->max_occupancy_pct) / 100.0 * 100);
            $flags[] = [
                'flag_type' => 'rate_override_anomaly',
                'severity' => 'medium',
                'description' => "Rule (min: {$rule->min_occupancy_pct}%, max: {$rule->max_occupancy_pct}%) applies a {$discountPct}% markup (threshold: {$threshold}%).",
                'subject_type' => YieldRule::class,
                'subject_id' => $rule->id,
                'evidence' => [
                    'min_occupancy' => $rule->min_occupancy_pct,
                    'max_occupancy' => $rule->max_occupancy_pct,
                    'markup_pct' => $discountPct,
                    'multiplier' => $rule->rate_multiplier,
                ],
            ];
        }

        return $flags;
    }

    /**
     * @return list<array{flag_type: string, severity: string, description: string, subject_type?: class-string, subject_id?: int, evidence?: array<string, mixed>}>
     */
    private function checkVoidedTransactions(Branch $branch, string $businessDate): array
    {
        $settings = is_array($branch->settings) ? $branch->settings : [];
        $thresholdSetting = $settings['audit_voided_txn_threshold'] ?? 10000;
        $threshold = (int) (is_int($thresholdSetting) ? $thresholdSetting : 10000);

        $voidedTxns = Transaction::whereHas('folio', function ($q) use ($branch) {
            $q->where('branch_id', $branch->id);
        })
            ->where('is_voided', true)
            ->whereDate('voided_at', $businessDate)
            ->get();

        $flags = [];
        foreach ($voidedTxns as $txn) {
            $amount = $txn->amount ?? 0;
            if ($amount >= $threshold) {
                $flags[] = [
                    'flag_type' => 'voided_charge',
                    'severity' => 'high',
                    'description' => "Txn #{$txn->id} voided for amount $".number_format($amount / 100, 2).' (threshold: $'.number_format($threshold / 100, 2).').',
                    'subject_type' => Transaction::class,
                    'subject_id' => $txn->id,
                    'evidence' => [
                        'amount' => $amount,
                        'category' => $txn->category,
                    ],
                ];
            }
        }

        return $flags;
    }

    /**
     * @return list<array{flag_type: string, severity: string, description: string, subject_type?: class-string, subject_id?: int, evidence?: array<string, mixed>}>
     */
    private function checkRefunds(Branch $branch, string $businessDate): array
    {
        $settings = is_array($branch->settings) ? $branch->settings : [];
        $thresholdSetting = $settings['audit_refund_threshold'] ?? 50000;
        $threshold = (int) (is_int($thresholdSetting) ? $thresholdSetting : 50000);

        $refunds = Transaction::whereHas('folio', function ($q) use ($branch) {
            $q->where('branch_id', $branch->id);
        })
            ->where('type', 'refund')
            ->whereDate('created_at', $businessDate)
            ->get();

        $flags = [];
        foreach ($refunds as $refund) {
            $amount = abs($refund->amount ?? 0);
            if ($amount >= $threshold) {
                $flags[] = [
                    'flag_type' => 'cash_drawer',
                    'severity' => 'high',
                    'description' => "Refund #{$refund->id} for $".number_format($amount / 100, 2).' exceeds $'.number_format($threshold / 100, 2).' threshold.',
                    'subject_type' => Transaction::class,
                    'subject_id' => $refund->id,
                    'evidence' => [
                        'amount' => $amount,
                    ],
                ];
            }
        }

        return $flags;
    }
}
