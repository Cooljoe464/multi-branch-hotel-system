<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Jobs\ExportDailyJournalJob;
use App\Models\AccountingExport;
use App\Models\AccountingLink;
use App\Models\Branch;
use App\Models\ChartAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountingExportController extends Controller
{
    use EnsuresBranchAccess;

    public function index(Branch $branch): Response
    {
        $this->ensureBranchAccess($branch);

        $links = AccountingLink::where('branch_id', $branch->id)->orderBy('provider')->get()->map(fn (AccountingLink $l) => [
            'id' => $l->id,
            'provider' => $l->provider,
            'sandbox' => $l->sandbox,
            'is_active' => $l->is_active,
            'connected' => $l->readTokens() !== [],
            'mapped' => is_array($l->account_map) ? count($l->account_map) : 0,
        ])->all();

        $exports = AccountingExport::forBranch($branch->id)->latest('business_date')->limit(60)->get()->map(fn (AccountingExport $e) => [
            'id' => $e->id,
            'business_date' => $e->business_date->toDateString(),
            'provider' => $e->provider,
            'status' => $e->status,
            'external_id' => $e->external_id,
            'totals' => $e->totals,
            'last_error' => $e->last_error,
        ])->all();

        return Inertia::render('finance/Accounting', [
            'branch' => $branch->only(['id', 'name']),
            'links' => $links,
            'exports' => $exports,
            'chartCodes' => ChartAccount::orderBy('code')->pluck('code')->all(),
            'providers' => AccountingLink::PROVIDERS,
        ]);
    }

    public function storeLink(Request $request, Branch $branch): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $request->validate([
            'provider' => 'required|string|in:xero,quickbooks,sage,fake',
            'sandbox' => 'nullable|boolean',
        ]);

        AccountingLink::updateOrCreate(
            ['branch_id' => $branch->id, 'provider' => $request->string('provider')->value()],
            ['sandbox' => $request->boolean('sandbox', true), 'is_active' => true],
        );

        return redirect()->route('accounting.index', $branch)
            ->with('toast', ['type' => 'success', 'message' => 'Provider linked. Map the chart, then export.']);
    }

    public function storeMap(Request $request, Branch $branch, AccountingLink $link): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($link->branch_id === $branch->id, 404);

        $request->validate([
            'account_map' => 'required|array',
            'account_map.*' => 'nullable|string|max:64',
        ]);

        $map = [];
        foreach ($request->array('account_map') as $code => $external) {
            if (is_string($code) && is_string($external) && $external !== '') {
                $map[$code] = $external;
            }
        }

        $link->update(['account_map' => $map]);

        return redirect()->route('accounting.index', $branch)
            ->with('toast', ['type' => 'success', 'message' => 'Mapping saved.']);
    }

    public function export(Request $request, Branch $branch): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $request->validate([
            'provider' => 'required|string|in:xero,quickbooks,sage,fake',
            'business_date' => 'required|date_format:Y-m-d',
        ]);

        ExportDailyJournalJob::dispatch($branch->id, $request->string('business_date')->value(), $request->string('provider')->value());

        return redirect()->route('accounting.index', $branch)
            ->with('toast', ['type' => 'success', 'message' => 'Export queued.']);
    }

    public function retry(Branch $branch, AccountingExport $export): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($export->branch_id === $branch->id, 404);

        ExportDailyJournalJob::dispatch($branch->id, $export->business_date->toDateString(), $export->provider);

        return redirect()->route('accounting.index', $branch)
            ->with('toast', ['type' => 'success', 'message' => 'Export requeued.']);
    }
}
