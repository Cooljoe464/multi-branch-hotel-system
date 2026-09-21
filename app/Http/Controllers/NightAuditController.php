<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Branch;
use App\Models\NightAuditRun;
use App\Services\NightAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NightAuditController extends Controller
{
    use EnsuresBranchAccess;

    public function index(Branch $branch): Response
    {
        $this->ensureBranchAccess($branch);

        $runs = NightAuditRun::forBranch($branch->id)
            ->orderByDesc('business_date')
            ->limit(31)
            ->get();

        return Inertia::render('night-audit/Show', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'runs' => $runs,
        ]);
    }

    public function run(Request $request, Branch $branch, NightAuditService $audit): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $request->validate(['business_date' => 'required|date']);

        $run = $audit->forBranch($branch)->run(
            $request->string('business_date')->value(),
            null,
            $request->user(),
        );

        return $this->flashSuccess(
            $run->status === NightAuditRun::STATUS_FAILED
                ? 'Night audit failed — see run details.'
                : 'Night audit completed.'
        );
    }

    public function retry(Request $request, Branch $branch, NightAuditRun $run, NightAuditService $audit): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($run->branch_id === $branch->id, 404);

        $resumed = $audit->forBranch($branch)->run(
            $run->business_date->toDateString(),
            $run->idempotency_key,
            $request->user(),
        );

        return $this->flashSuccess(
            $resumed->status === NightAuditRun::STATUS_FAILED
                ? 'Night audit failed again — see run details.'
                : 'Night audit resumed and completed.'
        );
    }
}
