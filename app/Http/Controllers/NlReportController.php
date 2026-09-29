<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Branch;
use App\Models\ReportQuery;
use App\Services\NlReportingService;
use App\Services\Reporting\SqlGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NlReportController extends Controller
{
    use EnsuresBranchAccess;

    public function index(Branch $branch): Response
    {
        $this->ensureBranchAccess($branch);

        $user = request()->user();

        $queries = ReportQuery::forBranch($branch->id)
            ->where(function ($q) use ($user) {
                $q->where('shared', true);

                if ($user) {
                    $q->orWhere('owner_id', $user->id);
                }
            })
            ->latest('id')
            ->limit(50)
            ->get(['id', 'name', 'nl', 'shared', 'owner_id']);

        return Inertia::render('analytics/Ask', [
            'branch' => $branch->only(['id', 'name']),
            'queries' => $queries,
        ]);
    }

    public function ask(Branch $branch, Request $request, NlReportingService $reporting): JsonResponse
    {
        $this->ensureBranchAccess($branch);

        $request->validate(['question' => 'required|string|max:500']);

        $result = $reporting->ask($branch, $request->user(), $request->string('question')->value());

        return response()->json(['data' => $result]);
    }

    public function poll(Branch $branch, string $key, NlReportingService $reporting): JsonResponse
    {
        $this->ensureBranchAccess($branch);

        $result = $reporting->poll($key, request()->user());

        if ($result === null) {
            abort(404, 'Unknown or expired query key.');
        }

        return response()->json(['data' => $result]);
    }

    public function export(Branch $branch, string $key, NlReportingService $reporting): StreamedResponse
    {
        $this->ensureBranchAccess($branch);

        $result = $reporting->poll($key, request()->user());

        if ($result === null || $result['status'] !== 'completed') {
            abort(404, 'Result is not ready.');
        }

        $columns = $result['columns'];
        $rows = $result['rows'];

        return response()->streamDownload(function () use ($columns, $rows) {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fputcsv($handle, array_map(strval(...), $columns));

            foreach ($rows as $row) {
                fputcsv($handle, array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $row));
            }

            fclose($handle);
        }, "nl-query-{$key}.csv", ['Content-Type' => 'text/csv']);
    }

    public function store(Branch $branch, Request $request): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $request->validate([
            'name' => 'required|string|max:128',
            'nl' => 'required|string|max:500',
            'sql' => 'required|string|max:2000',
            'shared' => 'nullable|boolean',
        ]);

        $shared = $request->boolean('shared');

        if ($shared && ! $user->can('analytics.manage')) {
            abort(403, 'Only analytics managers share queries.');
        }

        SqlGuard::validate($request->string('sql')->value());

        ReportQuery::create([
            'branch_id' => $branch->id,
            'owner_id' => $user->id,
            'name' => $request->string('name')->value(),
            'nl' => $request->string('nl')->value(),
            'sql' => $request->string('sql')->value(),
            'shared' => $shared,
        ]);

        return redirect()->route('nl-ask.index', $branch)
            ->with('toast', ['type' => 'success', 'message' => 'Query saved.']);
    }
}
