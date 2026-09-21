<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Branch;
use App\Models\TrialBalance;
use App\Services\TrialBalanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TrialBalanceController extends Controller
{
    use EnsuresBranchAccess;

    public function index(Request $request, Branch $branch): Response
    {
        $this->ensureBranchAccess($branch);

        $balances = TrialBalance::forBranch($branch->id)
            ->orderByDesc('business_date')
            ->limit(31)
            ->get();

        return Inertia::render('finance/TrialBalance', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'balances' => $balances,
        ]);
    }

    public function close(Request $request, Branch $branch, TrialBalanceService $trialBalances): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $request->validate(['business_date' => 'required|date']);
        $date = $request->string('business_date')->value();

        $balance = $trialBalances->close($branch, $date);

        return $this->flashSuccess(
            $balance->balanced
                ? "Trial balance for {$date} reconciles."
                : "Trial balance for {$date} does NOT reconcile — see flags."
        );
    }
}
