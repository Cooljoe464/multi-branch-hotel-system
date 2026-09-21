<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Branch;
use App\Models\Budget;
use App\Services\RevenueAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RevenueReportController extends Controller
{
    use EnsuresBranchAccess;

    public function index(Request $request, Branch $branch): Response
    {
        $this->ensureBranchAccess($branch);

        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $from = $request->string('from')->value() !== '' ? $request->string('from')->value() : now()->subDays(30)->toDateString();
        $to = $request->string('to')->value() !== '' ? $request->string('to')->value() : now()->addDays(30)->toDateString();

        return Inertia::render('analytics/Revenue', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'filters' => ['from' => $from, 'to' => $to],
            'metrics' => (new RevenueAnalyticsService)->metrics($branch, $from, $to),
            'budgets' => Budget::forBranch($branch->id)->orderBy('month')->get(),
        ]);
    }

    public function api(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'segment' => 'nullable|string|max:32',
        ]);

        $branch = Branch::findOrFail($request->integer('branch_id'));

        $this->ensureBranchAccess($branch);

        $metrics = (new RevenueAnalyticsService)->metrics(
            $branch,
            $request->string('from')->value(),
            $request->string('to')->value(),
        );

        $segment = $request->string('segment')->value();

        if ($segment !== '') {
            $metrics['by_segment'] = array_filter(
                $metrics['by_segment'],
                fn ($key) => $key === $segment,
                ARRAY_FILTER_USE_KEY,
            );
        }

        return response()->json($metrics);
    }

    public function storeBudget(Request $request, Branch $branch): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $request->validate([
            'month' => 'required|date_format:Y-m',
            'room_nights_target' => 'required|integer|min:0',
            'revenue_target_minor' => 'required|integer|min:0',
        ]);

        Budget::updateOrCreate(
            [
                'branch_id' => $branch->id,
                'month' => $request->string('month')->value().'-01',
            ],
            [
                'room_nights_target' => $request->integer('room_nights_target'),
                'revenue_target_minor' => $request->integer('revenue_target_minor'),
            ],
        );

        return $this->flashSuccess('Budget saved.');
    }
}
