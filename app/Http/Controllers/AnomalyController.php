<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\AnomalyFinding;
use App\Models\AnomalyRule;
use App\Models\Branch;
use App\Services\AnomalyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnomalyController extends Controller
{
    use EnsuresBranchAccess;

    public function index(Branch $branch): Response
    {
        $this->ensureBranchAccess($branch);

        $findings = AnomalyFinding::forBranch($branch->id)
            ->with('subject')
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (AnomalyFinding $f) => [
                'id' => $f->id,
                'rule_code' => $f->rule_code,
                'subject_type' => $f->subject_type !== null ? class_basename($f->subject_type) : null,
                'subject_id' => $f->subject_id,
                'score' => $f->score,
                'status' => $f->status,
                'evidence' => $f->evidence ?? [],
                'created_at' => $f->created_at?->toIso8601String(),
            ])
            ->all();

        $rules = AnomalyRule::where(function ($q) use ($branch) {
            $q->whereNull('branch_id')->orWhere('branch_id', $branch->id);
        })->orderBy('code')->get()->map(fn (AnomalyRule $r) => [
            'id' => $r->id,
            'branch_id' => $r->branch_id,
            'code' => $r->code,
            'params' => $r->params ?? [],
            'active' => $r->active,
        ])->all();

        return Inertia::render('audit/Anomalies', [
            'branch' => $branch->only(['id', 'name']),
            'findings' => $findings,
            'rules' => $rules,
        ]);
    }

    public function confirm(Branch $branch, AnomalyFinding $finding, Request $request, AnomalyService $anomalies): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($finding->branch_id === $branch->id, 404);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $anomalies->confirm($finding, $user);

        return redirect()->route('anomalies.index', $branch)
            ->with('toast', ['type' => 'success', 'message' => "Finding #{$finding->id} confirmed."]);
    }

    public function clear(Branch $branch, AnomalyFinding $finding, Request $request, AnomalyService $anomalies): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($finding->branch_id === $branch->id, 404);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $anomalies->clear($finding, $user);

        return redirect()->route('anomalies.index', $branch)
            ->with('toast', ['type' => 'success', 'message' => "Finding #{$finding->id} cleared as false positive."]);
    }

    public function tune(Request $request, Branch $branch, AnomalyRule $rule): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($rule->branch_id === null || $rule->branch_id === $branch->id, 404);

        $request->validate([
            'params' => 'required|array',
            'params.z_threshold' => 'nullable|numeric|min:1|max:10',
            'params.sensitivity' => 'nullable|numeric|min:0.05|max:5',
            'params.min_baseline_days' => 'nullable|integer|min:3|max:28',
            'active' => 'nullable|boolean',
        ]);

        $params = [];
        foreach ($request->array('params') as $key => $value) {
            if (is_string($key) && (is_int($value) || is_float($value))) {
                $params[$key] = $value;
            }
        }

        $target = $rule->branch_id === null
            ? AnomalyRule::firstOrCreate(
                ['branch_id' => $branch->id, 'code' => $rule->code],
                ['params' => $rule->params, 'active' => true],
            )
            : $rule;

        $target->tune($params);

        if ($request->has('active')) {
            $target->update(['active' => $request->boolean('active')]);
        }

        return redirect()->route('anomalies.index', $branch)
            ->with('toast', ['type' => 'success', 'message' => "Rule {$rule->code} tuned for {$branch->name}."]);
    }
}
