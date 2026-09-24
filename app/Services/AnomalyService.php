<?php

namespace App\Services;

use App\Events\AnomalyRaised;
use App\Jobs\AnomalyScanJob;
use App\Models\AnomalyFinding;
use App\Models\AnomalyRule;
use App\Models\AuditFlag;
use App\Models\Branch;
use App\Models\RateOverride;
use App\Models\Reservation;
use App\Models\Transaction;
use App\Models\User;
use App\Support\BranchTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Loss-prevention anomaly detection. Rate-based detectors compare
 * today against a trailing baseline (proportions, never raw counts,
 * so a legit conference day stays quiet); entity detectors fire on
 * deterministic abuse shapes. Scans only ever create open findings
 * and audit flags — confirmation is human, via Auditor action.
 */
class AnomalyService
{
    public const BASELINE_DAYS = 28;

    public const FLAG_GATE = 30.0;

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function defaultRules(): array
    {
        return [
            'void_rate' => ['z_threshold' => 3.0, 'sensitivity' => 1.0, 'min_baseline_days' => 7],
            'discount_rate' => ['z_threshold' => 3.0, 'sensitivity' => 1.0, 'min_baseline_days' => 7],
            'refund_burst' => ['z_threshold' => 3.0, 'sensitivity' => 1.0, 'min_baseline_days' => 7],
            'rate_change_after_hours' => ['quiet_from' => 6, 'quiet_until' => 22, 'score' => 75.0],
            'noshow_fee_skip' => ['score' => 80.0],
        ];
    }

    public static function seedDefaults(): void
    {
        foreach (self::defaultRules() as $code => $params) {
            AnomalyRule::firstOrCreate(
                ['branch_id' => null, 'code' => $code],
                ['params' => $params, 'active' => true],
            );
        }
    }

    /**
     * @return list<AnomalyFinding>
     */
    public function scanBranch(Branch $branch, ?string $date = null): array
    {
        self::seedDefaults();

        $date ??= BranchTime::today($branch);
        $findings = [];

        foreach (['void_rate', 'discount_rate', 'refund_burst'] as $code) {
            $finding = $this->scanRate($branch, $code, $date);

            if ($finding) {
                $findings[] = $finding;
            }
        }

        foreach ($this->scanAfterHoursOverrides($branch) as $finding) {
            $findings[] = $finding;
        }

        foreach ($this->scanNoshowSkips($branch) as $finding) {
            $findings[] = $finding;
        }

        return $findings;
    }

    /**
     * @return array{scanned: int, findings: int}
     */
    public function scanAll(?string $date = null): array
    {
        $scanned = 0;
        $findings = 0;

        foreach (Branch::where('is_active', true)->orderBy('id')->get() as $branch) {
            $scanned++;
            $findings += count($this->scanBranch($branch, $date));
        }

        return ['scanned' => $scanned, 'findings' => $findings];
    }

    public function ruleFor(?int $branchId, string $code): ?AnomalyRule
    {
        if ($branchId !== null) {
            $override = AnomalyRule::active()->where('branch_id', $branchId)->where('code', $code)->first();

            if ($override) {
                return $override;
            }
        }

        return AnomalyRule::active()->whereNull('branch_id')->where('code', $code)->first();
    }

    public function confirm(AnomalyFinding $finding, User $confirmedBy): AnomalyFinding
    {
        if ($finding->status !== AnomalyFinding::STATUS_OPEN) {
            return $finding;
        }

        $finding->update(['status' => AnomalyFinding::STATUS_CONFIRMED]);

        AuditFlag::where('metadata->anomaly_finding_id', $finding->id)->update([
            'is_reviewed' => true,
            'reviewed_by' => $confirmedBy->id,
            'reviewed_at' => now(),
        ]);

        return $finding->fresh() ?? $finding;
    }

    public function clear(AnomalyFinding $finding, ?User $clearedBy = null): AnomalyFinding
    {
        if ($finding->status !== AnomalyFinding::STATUS_OPEN) {
            return $finding;
        }

        $finding->update(['status' => AnomalyFinding::STATUS_CLEARED]);

        AuditFlag::where('metadata->anomaly_finding_id', $finding->id)->update([
            'is_reviewed' => true,
            'reviewed_by' => $clearedBy?->id,
            'reviewed_at' => now(),
        ]);

        return $finding->fresh() ?? $finding;
    }

    /**
     * Proportion of today's folio transactions matching the rule
     * vs the trailing baseline. Returns null when the baseline is
     * too thin to judge.
     *
     * @return array{z: float, mean: float, std: float, days: int, today: float}|null
     */
    public function baseline(Branch $branch, string $code, string $date): ?array
    {
        $rule = $this->ruleFor($branch->id, $code);

        if (! $rule) {
            return null;
        }

        $minDays = $rule->paramInt('min_baseline_days', 7);

        $history = [];
        $cursor = Carbon::parse($date)->subDay();

        for ($i = 0; $i < self::BASELINE_DAYS; $i++) {
            $day = $cursor->toDateString();
            $rate = $this->dailyRate($branch->id, $code, $day);

            if ($rate !== null) {
                $history[] = $rate;
            }

            $cursor->subDay();
        }

        if (count($history) < $minDays) {
            return null;
        }

        $mean = array_sum($history) / count($history);
        $variance = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $history)) / count($history);
        $std = sqrt($variance);

        $today = $this->dailyRate($branch->id, $code, $date);

        if ($today === null) {
            return null;
        }

        if ($std <= 0) {
            return $today > $mean
                ? ['z' => 10.0, 'mean' => $mean, 'std' => 0.0, 'days' => count($history), 'today' => $today]
                : null;
        }

        return [
            'z' => ($today - $mean) / $std,
            'mean' => $mean,
            'std' => $std,
            'days' => count($history),
            'today' => $today,
        ];
    }

    private function scanRate(Branch $branch, string $code, string $date): ?AnomalyFinding
    {
        $rule = $this->ruleFor($branch->id, $code);

        if (! $rule) {
            return null;
        }

        // One open finding per rule per day; re-scans are no-ops.
        $exists = AnomalyFinding::forBranch($branch->id)->open()
            ->where('rule_code', $code)
            ->where('subject_type', Branch::class)
            ->where('subject_id', $branch->id)
            ->whereJsonContains('evidence->date', $date)
            ->exists();

        if ($exists) {
            return null;
        }

        $stats = $this->baseline($branch, $code, $date);

        if ($stats === null) {
            return null;
        }

        $threshold = $rule->paramFloat('z_threshold', 3.0);
        $sensitivity = $rule->paramFloat('sensitivity', 1.0);

        if ($stats['z'] < $threshold) {
            return null;
        }

        $score = min(100.0, max(0.0, $stats['z'] * 10.0 * $sensitivity));

        if ($score < self::FLAG_GATE) {
            return null;
        }

        return $this->raise($branch, $rule, $branch, $score, [
            'date' => $date,
            'metric' => $code,
            'today_rate' => round($stats['today'], 4),
            'baseline_mean' => round($stats['mean'], 4),
            'baseline_std' => round($stats['std'], 4),
            'z' => round($stats['z'], 2),
            'baseline_days' => $stats['days'],
        ]);
    }

    /**
     * @return list<AnomalyFinding>
     */
    private function scanAfterHoursOverrides(Branch $branch): array
    {
        $rule = $this->ruleFor($branch->id, 'rate_change_after_hours');

        if (! $rule) {
            return [];
        }

        $from = $rule->paramInt('quiet_from', 6);
        $until = $rule->paramInt('quiet_until', 22);
        $score = $rule->paramFloat('score', 75.0);
        $findings = [];

        $overrides = RateOverride::forBranch($branch->id)
            ->where('created_at', '>=', now()->subDay())
            ->get();

        foreach ($overrides as $override) {
            if ($override->created_at === null) {
                continue;
            }

            $hour = (int) $override->created_at->copy()->setTimezone(BranchTime::timezone($branch))->format('G');

            // Branch-local hour: changes inside the quiet window are
            // routine; anything outside is worth a human look.
            $inQuiet = $from <= $until
                ? ($hour < $from || $hour >= $until)
                : ($hour < $from && $hour >= $until);

            if (! $inQuiet) {
                continue;
            }

            $finding = $this->raise($branch, $rule, $override, $score, [
                'override_id' => $override->id,
                'room_type_id' => $override->room_type_id,
                'changed_at_branch_hour' => $hour,
            ]);

            if ($finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * @return list<AnomalyFinding>
     */
    private function scanNoshowSkips(Branch $branch): array
    {
        $rule = $this->ruleFor($branch->id, 'noshow_fee_skip');

        if (! $rule) {
            return [];
        }

        $score = $rule->paramFloat('score', 80.0);
        $findings = [];

        $stays = Reservation::forBranch($branch->id)
            ->where('status', 'no_show')
            ->where('updated_at', '>=', now()->subDays(2))
            ->with('folios')
            ->get();

        foreach ($stays as $stay) {
            $feePosted = Transaction::whereIn('folio_id', $stay->folios->pluck('id'))
                ->where('category', 'no_show_fee')
                ->where('is_voided', false)
                ->exists();

            if ($feePosted || (int) $stay->no_show_fee_minor <= 0) {
                continue;
            }

            $finding = $this->raise($branch, $rule, $stay, $score, [
                'reservation_id' => $stay->id,
                'confirmation_number' => $stay->confirmation_number,
                'expected_fee_minor' => (int) $stay->no_show_fee_minor,
            ]);

            if ($finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Daily proportion for a rule. Null when the day has no
     * transactions (a quiet day is not an anomaly).
     */
    private function dailyRate(int $branchId, string $code, string $day): ?float
    {
        $base = Transaction::whereHas('folio', fn ($q) => $q->where('branch_id', $branchId))
            ->whereDate('business_date', $day);

        $total = (clone $base)->count();

        if ($total === 0) {
            return null;
        }

        $matching = match ($code) {
            'void_rate' => (clone $base)->where('is_voided', true)->count(),
            'discount_rate' => (clone $base)->where('is_voided', false)->where('category', 'adjustment')->where('type', 'credit')->count(),
            'refund_burst' => (clone $base)->where('is_voided', false)->where('type', 'refund')->count(),
            default => 0,
        };

        return $matching / $total;
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function raise(Branch $branch, AnomalyRule $rule, Model $subject, float $score, array $evidence): ?AnomalyFinding
    {
        $key = $subject->getKey();

        if (! is_int($key)) {
            return null;
        }

        try {
            $finding = DB::transaction(fn () => AnomalyFinding::firstOrCreate(
                [
                    'branch_id' => $branch->id,
                    'rule_code' => $rule->code,
                    'subject_type' => $subject::class,
                    'subject_id' => $key,
                ],
                [
                    'score' => $score,
                    'status' => AnomalyFinding::STATUS_OPEN,
                    'evidence' => $evidence,
                ]
            ));
        } catch (QueryException) {
            // Concurrent scan won the race; the row is already there.
            $finding = AnomalyFinding::where('branch_id', $branch->id)
                ->where('rule_code', $rule->code)
                ->where('subject_type', $subject::class)
                ->where('subject_id', $key)
                ->first();

            if (! $finding) {
                Log::warning('Anomaly finding lost to a race.', ['rule' => $rule->code]);

                return null;
            }
        }

        if (! $finding->wasRecentlyCreated) {
            return null;
        }

        AuditFlag::create([
            'branch_id' => $branch->id,
            'flag_type' => 'anomaly',
            'severity' => $score >= 60 ? 'high' : 'medium',
            'subject_type' => $subject::class,
            'subject_id' => $key,
            'description' => "Anomaly {$rule->code} (score ".round($score).')',
            'evidence' => $evidence,
            'metadata' => ['anomaly_finding_id' => $finding->id, 'rule_code' => $rule->code],
        ]);

        event(new AnomalyRaised($finding));

        return $finding;
    }

    public static function dispatchPostAudit(int $branchId, string $businessDate): void
    {
        AnomalyScanJob::dispatch($branchId, $businessDate);
    }
}
