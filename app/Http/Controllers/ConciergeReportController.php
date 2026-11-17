<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\User;
use App\Services\ConciergeAnalyticsService;
use App\Services\SentimentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConciergeReportController extends Controller
{
    use Concerns\EnsuresBranchAccess;

    /**
     * SaaS concierge ROI report: deflection rate, revenue impact and
     * sentiment trends for the resolved branch.
     *
     * @return array{branch_id: int, period_days: int, deflection: array{period_days: int, ai_handled: int, deflected_to_human: int, deflection_rate: float}, revenue_impact: array{period_days: int, actions_applied: int, revenue_minor: int}, sentiment_trend: array<int, array{date: string, avg_sentiment: float, guest_messages: int}>}
     */
    public function api(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $request->validate([
            'days' => 'nullable|integer|min:1|max:365',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
        ]);

        $branch = $this->resolveBranch($request, $user);

        $summary = (new ConciergeAnalyticsService(new SentimentService))
            ->forBranch($branch)
            ->getConciergeSummary(
                $request->integer('days') ?? 30,
                $request->string('start_date')->value() !== '' ? $request->string('start_date')->value() : null,
                $request->string('end_date')->value() !== '' ? $request->string('end_date')->value() : null,
            );

        return response()->json(['data' => $summary]);
    }

    /**
     * Staff-facing page for the same ROI numbers the SaaS API exposes.
     */
    public function show(Request $request, Branch $branch): Response
    {
        $this->ensureBranchAccess($branch);

        $request->validate(['days' => 'nullable|integer|in:7,30,90,365']);
        $days = $request->integer('days') ?: 30;

        $summary = (new ConciergeAnalyticsService(new SentimentService))
            ->forBranch($branch)
            ->getConciergeSummary($days);

        return Inertia::render('concierge/Report', [
            'branch' => $branch->only(['id', 'name', 'currency_code']),
            'days' => $days,
            'deflection' => $summary['deflection'],
            'revenueImpact' => $summary['revenue_impact'],
            'sentimentTrend' => $summary['sentiment_trend']->values()->all(),
        ]);
    }

    private function resolveBranch(Request $request, User $user): Branch
    {
        $branch = $request->attributes->get('branch');

        if ($branch instanceof Branch) {
            return $branch;
        }

        $branchId = $request->input('branch_id') ?? $request->input('_branch');

        if ($branchId instanceof Branch) {
            return $branchId;
        }

        if (is_numeric($branchId) && (int) $branchId > 0) {
            return Branch::findOrFail((int) $branchId);
        }

        $branch = $user->currentBranch ?? $user->defaultBranch;

        if (! $branch instanceof Branch) {
            abort(404, 'No branch context set.');
        }

        return $branch;
    }
}
