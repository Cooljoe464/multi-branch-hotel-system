<?php

namespace App\Http\Controllers;

use App\Exceptions\AvailabilityException;
use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Jobs\GenerateStatutoryReportJob;
use App\Models\Branch;
use App\Models\StatutoryReport;
use App\Services\StatutoryReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StatutoryReportController extends Controller
{
    use EnsuresBranchAccess;

    public function index(Branch $branch): Response
    {
        $this->ensureBranchAccess($branch);

        return Inertia::render('compliance/Statutory', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'reports' => StatutoryReport::forBranch($branch->id)
                ->with('generator')
                ->orderByDesc('id')
                ->paginate(25)
                ->withQueryString(),
        ]);
    }

    public function store(Request $request, Branch $branch): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $this->ensureBranchAccess($branch);

        $request->validate([
            'kind' => 'required|string|in:immigration,police,tourism_board',
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);

        try {
            (new StatutoryReportService)->generate(
                $branch,
                $request->string('kind')->value(),
                $request->string('from')->value(),
                $request->string('to')->value(),
                $user,
            );
        } catch (AvailabilityException $e) {
            return back()->withErrors(['report' => $e->getMessage()]);
        }

        return $this->flashSuccess('Report queued; the file lands here when ready.');
    }

    public function download(Request $request, Branch $branch, StatutoryReport $report): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $this->ensureBranchAccess($branch);
        abort_unless($report->branch_id === $branch->id, 404);

        try {
            $url = (new StatutoryReportService)->downloadUrl($report, $user);
        } catch (AvailabilityException $e) {
            return back()->withErrors(['report' => $e->getMessage()]);
        }

        return redirect()->away($url);
    }

    public function resubmit(Request $request, Branch $branch, StatutoryReport $report): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $this->ensureBranchAccess($branch);
        abort_unless($report->branch_id === $branch->id, 404);

        $report->update(['status' => StatutoryReport::STATUS_QUEUED]);
        GenerateStatutoryReportJob::dispatch($report->id);

        return $this->flashSuccess('Report re-queued under the same idempotency key.');
    }
}
