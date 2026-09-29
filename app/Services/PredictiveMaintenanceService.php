<?php

namespace App\Services;

use App\Events\AssetRiskRaised;
use App\Models\Asset;
use App\Models\AssetHealthScore;
use App\Models\Branch;
use App\Models\MaintenanceTicket;
use App\Models\User;
use App\Support\BranchTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Failure probability from ticket recurrence + PM overdue + age.
 * Above threshold the service drafts (never publishes) a PM work
 * order; rooms are never taken out of order by automation — a GM
 * confirms through the normal 3.2 flow.
 */
class PredictiveMaintenanceService
{
    public const THRESHOLD = 0.6;

    /**
     * @return array{scored: int, drafts: int}
     */
    public function scoreBranch(Branch $branch, ?string $date = null, float $threshold = self::THRESHOLD): array
    {
        $date ??= BranchTime::today($branch);
        $scored = 0;
        $drafts = 0;

        foreach (Asset::forBranch($branch->id)->orderBy('id')->get() as $asset) {
            $score = $this->scoreAt($asset, $date);

            AssetHealthScore::updateOrCreate(
                ['asset_id' => $asset->id, 'scored_on' => $date],
                ['failure_prob' => $score['prob'], 'signals' => $score['signals']],
            );
            $scored++;

            if ($score['prob'] >= $threshold && $this->draft($branch, $asset, $date, $score)) {
                $drafts++;
            }
        }

        return ['scored' => $scored, 'drafts' => $drafts];
    }

    /**
     * Backfill score history from ticket frequency (marked
     * heuristic — a cold-start approximation, not model output).
     */
    public function backfill(Branch $branch, int $days = 90): int
    {
        $count = 0;
        $today = BranchTime::today($branch);

        for ($i = $days; $i >= 1; $i--) {
            $date = Carbon::parse($today)->subDays($i)->toDateString();

            foreach (Asset::forBranch($branch->id)->orderBy('id')->get() as $asset) {
                $score = $this->scoreAt($asset, $date);

                AssetHealthScore::updateOrCreate(
                    ['asset_id' => $asset->id, 'scored_on' => $date],
                    ['failure_prob' => $score['prob'], 'signals' => array_merge($score['signals'], ['heuristic' => true])],
                );
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array{prob: float, signals: array<string, mixed>}
     */
    public function scoreAt(Asset $asset, string $date): array
    {
        $recent = $this->ticketCount($asset, Carbon::parse($date)->subDays(30)->toDateString(), $date);
        $older = $this->ticketCount($asset, Carbon::parse($date)->subDays(90)->toDateString(), Carbon::parse($date)->subDays(31)->toDateString());

        $recurrence = $recent >= 2 && $recent > $older ? 0.5 : ($recent >= 1 ? 0.2 : 0.0);

        $every = $asset->pmEveryDays();
        $overdue = false;

        if ($every !== null) {
            $last = $asset->last_pm_at?->toDateString();
            $overdue = $last === null || Carbon::parse($last)->addDays($every)->lessThan($date);
        }

        $ageYears = $asset->installed_on ? $asset->installed_on->diffInYears(Carbon::parse($date)) : 0;
        $age = $ageYears >= 5 ? 0.2 : ($ageYears >= 2 ? 0.1 : 0.0);

        return [
            'prob' => min(0.99, $recurrence + ($overdue ? 0.3 : 0.0) + $age),
            'signals' => [
                'tickets_30d' => $recent,
                'tickets_31_90d' => $older,
                'pm_overdue' => $overdue,
                'age_years' => $ageYears,
            ],
        ];
    }

    private function ticketCount(Asset $asset, string $from, string $to): int
    {
        return MaintenanceTicket::where('asset_id', $asset->id)
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->count();
    }

    /**
     * @param  array{prob: float, signals: array<string, mixed>}  $score
     */
    private function draft(Branch $branch, Asset $asset, string $date, array $score): bool
    {
        $open = MaintenanceTicket::where('asset_id', $asset->id)
            ->whereIn('status', ['draft', 'open', 'in_progress'])
            ->whereJsonContains('metadata->pm_draft', true)
            ->exists();

        if ($open) {
            return false;
        }

        $ticket = DB::transaction(fn () => MaintenanceTicket::create([
            'branch_id' => $branch->id,
            'asset_id' => $asset->id,
            'room_id' => $asset->room_id,
            'reported_by' => $this->systemUserId($branch),
            'category' => 'preventive',
            'priority' => $score['prob'] >= 0.8 ? 'high' : 'normal',
            'status' => 'draft',
            'title' => "PM draft: {$asset->name}",
            'description' => 'Predictive draft — publish after review. Rooms are never locked by automation.',
            'metadata' => ['pm_draft' => true, 'failure_prob' => $score['prob'], 'scored_on' => $date],
        ]));

        event(new AssetRiskRaised($asset, $score['prob'], $ticket->id));

        return true;
    }

    private function systemUserId(Branch $branch): int
    {
        return User::where('branch_id', $branch->id)->orderBy('id')->firstOrFail()->id;
    }
}
