<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Branch;
use App\Models\ChartAccount;
use App\Models\PostingRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChartAccountController extends Controller
{
    use EnsuresBranchAccess;

    public function index(Branch $branch): Response
    {
        $this->ensureBranchAccess($branch);

        return Inertia::render('finance/Chart', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'accounts' => ChartAccount::orderBy('code')->get(),
            'rules' => PostingRule::orderBy('event')->get(),
        ]);
    }

    public function updateRule(Request $request, Branch $branch, PostingRule $rule): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $request->validate([
            'debit_account' => 'required|string|max:32|exists:chart_accounts,code',
            'credit_account' => 'required|string|max:32|exists:chart_accounts,code',
        ]);

        $rule->update([
            'debit_account' => $request->string('debit_account')->value(),
            'credit_account' => $request->string('credit_account')->value(),
        ]);

        return $this->flashSuccess("Posting rule {$rule->event} updated. Posted history is untouched.");
    }
}
