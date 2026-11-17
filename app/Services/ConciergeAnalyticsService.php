<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\ConciergeAction;
use App\Models\ConciergeMessage;
use App\Models\ConciergeThread;
use App\Support\BranchTime;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * SaaS concierge ROI reporting, per branch: deflection rate (AI
 * answers without staff intervention), revenue impact of AI-recommended
 * actions that were applied, and guest sentiment trends.
 */
class ConciergeAnalyticsService
{
    private ?Branch $branch = null;

    public function __construct(private readonly SentimentService $sentiment) {}

    public function forBranch(Branch $branch): self
    {
        $this->branch = $branch;

        return $this;
    }

    /**
     * Branch-scoped SaaS concierge ROI summary.
     *
     * @return array{branch_id: int, period_days: int, deflection: array{period_days: int, ai_handled: int, deflected_to_human: int, deflection_rate: float}, revenue_impact: array{period_days: int, actions_applied: int, revenue_minor: int}, sentiment_trend: Collection<int, array{date: string, avg_sentiment: float, guest_messages: int}>}
     */
    public function getConciergeSummary(int $days = 30, ?string $startDate = null, ?string $endDate = null): array
    {
        $branch = $this->requireBranch();
        $period = $this->period($branch, $days, $startDate, $endDate);

        return [
            'branch_id' => $branch->id,
            'period_days' => $period['period_days'],
            'deflection' => $this->deflectionFor($branch, $period),
            'revenue_impact' => $this->revenueImpactFor($branch, $period),
            'sentiment_trend' => $this->sentimentTrendFromScores($this->sentimentScoresFor($branch, $period)),
        ];
    }

    /**
     * Share of concierge conversations the AI resolved on its own
     * without a human handoff. Conversations the AI answered itself are
     * deflected away from the human queue; the rest went to a human.
     *
     * @return array{period_days: int, ai_handled: int, deflected_to_human: int, deflection_rate: float}
     */
    public function getDeflectionSummary(int $days = 30, ?string $startDate = null, ?string $endDate = null): array
    {
        $period = $this->period($this->requireBranch(), $days, $startDate, $endDate);

        return $this->deflectionFor($this->requireBranch(), $period);
    }

    /**
     * Revenue impact: fees of concierge-recommended actions (late
     * checkout etc.) that were applied to reservations during the period.
     *
     * @return array{period_days: int, actions_applied: int, revenue_minor: int}
     */
    public function getRevenueImpact(int $days = 30, ?string $startDate = null, ?string $endDate = null): array
    {
        $period = $this->period($this->requireBranch(), $days, $startDate, $endDate);

        /** @var EloquentCollection<int, ConciergeAction> $applied */
        $applied = ConciergeAction::query()
            ->whereBetween('applied_at', [$period['start'], $period['end']])
            ->where('status', ConciergeAction::STATUS_APPLIED)
            ->get();

        $revenueMinor = 0;

        foreach ($applied as $action) {
            $feeMinor = $action->payload['fee_minor'] ?? null;

            if (is_numeric($feeMinor)) {
                $revenueMinor += (int) $feeMinor;
            }
        }

        return [
            'period_days' => $period['period_days'],
            'actions_applied' => $applied->count(),
            'revenue_minor' => $revenueMinor,
        ];
    }

    /**
     * Daily average guest sentiment (0..1) across concierge
     * conversations within the period.
     *
     * @return Collection<int, array{date: string, avg_sentiment: float, guest_messages: int}>
     */
    public function getSentimentTrend(int $days = 30, ?string $startDate = null, ?string $endDate = null): Collection
    {
        $period = $this->period($this->requireBranch(), $days, $startDate, $endDate);

        /** @var array<string, array{date: string, total: float, messages: int}> $scores */
        $scores = $this->sentimentScoresFor($this->requireBranch(), $period);

        return $this->sentimentTrendFromScores($scores);
    }

    /**
     * SaaS tenant-level concierge ROI: aggregates the branch-scoped
     * metrics of every branch owned by the tenant.
     *
     * @return array{period_days: int, branches: int, ai_handled: int, deflected_to_human: int, deflection_rate: float, revenue_impact: array{period_days: int, actions_applied: int, revenue_minor: int}, sentiment_trend: Collection<int, array{date: string, avg_sentiment: float, guest_messages: int}>}
     */
    public function getTenantSummary(int $tenantId, int $days = 30, ?string $startDate = null, ?string $endDate = null): array
    {
        /** @var EloquentCollection<int, Branch> $branches */
        $branches = Branch::where('tenant_id', $tenantId)->get();

        if ($branches->isEmpty()) {
            return [
                'period_days' => $days,
                'branches' => 0,
                'ai_handled' => 0,
                'deflected_to_human' => 0,
                'deflection_rate' => 0.0,
                'revenue_impact' => [
                    'period_days' => $days,
                    'actions_applied' => 0,
                    'revenue_minor' => 0,
                ],
                'sentiment_trend' => collect(),
            ];
        }

        $firstBranch = $branches->first();
        $period = $firstBranch instanceof Branch ? $this->period($firstBranch, $days, $startDate, $endDate) : null;

        if ($period === null) {
            return [
                'period_days' => $days,
                'branches' => $branches->count(),
                'ai_handled' => 0,
                'deflected_to_human' => 0,
                'deflection_rate' => 0.0,
                'revenue_impact' => [
                    'period_days' => $days,
                    'actions_applied' => 0,
                    'revenue_minor' => 0,
                ],
                'sentiment_trend' => collect(),
            ];
        }

        $aiHandled = 0;
        $deflectedToHuman = 0;
        $conversations = 0;
        $revenueMinor = 0;
        $actionsApplied = 0;
        $sentimentByDate = [];

        foreach ($branches as $tenantBranch) {
            $deflection = $this->deflectionFor($tenantBranch, $period);
            $aiHandled += $deflection['ai_handled'];
            $deflectedToHuman += $deflection['deflected_to_human'];
            $conversations += $deflection['ai_handled'] + $deflection['deflected_to_human'];

            $revenueImpact = $this->revenueImpactFor($tenantBranch, $period);
            $actionsApplied += $revenueImpact['actions_applied'];
            $revenueMinor += $revenueImpact['revenue_minor'];

            $scores = $this->sentimentScoresFor($tenantBranch, $period);

            foreach ($scores as $date => $score) {
                $entry = $sentimentByDate[$date] ?? ['date' => $date, 'total' => 0.0, 'messages' => 0];

                $entry['total'] += $score['total'];
                $entry['messages'] += $score['messages'];

                $sentimentByDate[$date] = $entry;
            }
        }

        $trend = $this->sentimentTrendFromScores($sentimentByDate);

        return [
            'period_days' => $period['period_days'],
            'branches' => $branches->count(),
            'ai_handled' => $aiHandled,
            'deflected_to_human' => $deflectedToHuman,
            'deflection_rate' => $conversations > 0 ? round(($aiHandled / $conversations) * 100, 1) : 0.0,
            'revenue_impact' => [
                'period_days' => $period['period_days'],
                'actions_applied' => $actionsApplied,
                'revenue_minor' => $revenueMinor,
            ],
            'sentiment_trend' => $trend->values(),
        ];
    }

    /**
     * @return array{period_days: int, ai_handled: int, deflected_to_human: int, deflection_rate: float}
     */
    private function deflectionFor(Branch $branch, array $period): array
    {
        /** @var EloquentCollection<int, ConciergeThread> $threads */
        $threads = ConciergeThread::forBranch($branch->id)
            ->whereBetween('created_at', [$period['start'], $period['end']])
            ->get();

        /** @var Collection<int, int> $assistantThreadIds */
        $assistantThreadIds = ConciergeMessage::query()
            ->whereIn('concierge_thread_id', $threads->pluck('id'))
            ->where('role', ConciergeMessage::ROLE_ASSISTANT)
            ->pluck('concierge_thread_id');

        $aiHandled = 0;
        $deflectedToHuman = 0;

        foreach ($threads as $thread) {
            if ($thread->handoff_at !== null) {
                $deflectedToHuman++;

                continue;
            }

            if ($assistantThreadIds->contains($thread->id)) {
                $aiHandled++;
            }
        }

        $conversations = $aiHandled + $deflectedToHuman;

        return [
            'period_days' => $period['period_days'],
            'ai_handled' => $aiHandled,
            'deflected_to_human' => $deflectedToHuman,
            'deflection_rate' => $conversations > 0 ? round(($aiHandled / $conversations) * 100, 1) : 0.0,
        ];
    }

    private function revenueImpactFor(Branch $branch, array $period): array
    {
        /** @var EloquentCollection<int, ConciergeAction> $applied */
        $applied = ConciergeAction::query()
            ->whereBetween('applied_at', [$period['start'], $period['end']])
            ->where('status', ConciergeAction::STATUS_APPLIED)
            ->get();

        $revenueMinor = 0;

        foreach ($applied as $action) {
            $feeMinor = $action->payload['fee_minor'] ?? null;

            if (is_numeric($feeMinor)) {
                $revenueMinor += (int) $feeMinor;
            }
        }

        return [
            'period_days' => $period['period_days'],
            'actions_applied' => $applied->count(),
            'revenue_minor' => $revenueMinor,
        ];
    }

    /**
     * @param  array{start: Carbon, end: Carbon, period_days: int}  $period
     * @return array<string, array{date: string, total: float, messages: int}>
     */
    private function sentimentScoresFor(Branch $branch, array $period): array
    {
        $messages = ConciergeMessage::query()
            ->whereIn('concierge_thread_id', ConciergeThread::forBranch($branch->id)->whereBetween('created_at', [$period['start'], $period['end']])->pluck('id'))
            ->where('role', ConciergeMessage::ROLE_GUEST)
            ->whereBetween('created_at', [$period['start'], $period['end']])
            ->get();

        /** @var array<string, array{date: string, total: float, messages: int}> $scores */
        $scores = [];

        foreach ($messages as $message) {
            $date = (string) ($message->created_at?->toDateString() ?? '');

            if ($date === '') {
                continue;
            }

            $score = $this->sentiment->score($message->body);
            $entry = $scores[$date] ?? ['date' => $date, 'total' => 0.0, 'messages' => 0];

            $entry['total'] += $score;
            $entry['messages']++;

            $scores[$date] = $entry;
        }

        return $scores;
    }

    /**
     * @param  array<string, array{date: string, total: float, messages: int}>  $scores
     * @return Collection<int, array{date: string, avg_sentiment: float, guest_messages: int}>
     */
    private function sentimentTrendFromScores(array $scores): Collection
    {
        /** @var Collection<int, array{date: string, avg_sentiment: float, guest_messages: int}> $trend */
        $trend = collect($scores)
            ->sortBy('date')
            ->values()
            ->map(fn (array $entry): array => [
                'date' => $entry['date'],
                'avg_sentiment' => $entry['messages'] > 0 ? round($entry['total'] / $entry['messages'], 3) : 0.0,
                'guest_messages' => $entry['messages'],
            ]);

        return $trend->values();
    }

    /**
     * @return array{start: Carbon, end: Carbon, period_days: int}
     */
    private function period(Branch $branch, int $days, ?string $startDate, ?string $endDate): array
    {
        $start = $startDate ? Carbon::parse($startDate)->startOfDay() : BranchTime::now($branch)->subDays($days)->startOfDay();
        $end = $endDate ? Carbon::parse($endDate)->endOfDay() : BranchTime::now($branch)->endOfDay();

        return [
            'start' => $start,
            'end' => $end,
            'period_days' => $startDate && $endDate ? (int) $start->diffInDays($end) : $days,
        ];
    }

    private function requireBranch(): Branch
    {
        if ($this->branch === null) {
            throw new \LogicException('Branch must be set before computing concierge analytics.');
        }

        return $this->branch;
    }
}
