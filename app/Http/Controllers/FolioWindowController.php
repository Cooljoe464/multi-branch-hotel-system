<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Folio;
use App\Models\FolioRoutingRule;
use App\Models\FolioWindow;
use App\Models\Transaction;
use App\Services\FolioService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FolioWindowController extends Controller
{
    use EnsuresBranchAccess;

    public function storeWindow(Request $request, Folio $folio): RedirectResponse
    {
        $this->ensureBranchAccess($folio->branch);

        $request->validate([
            'code' => 'required|string|max:16',
            'payer_type' => 'required|in:guest,company,group_master',
        ]);

        (new FolioService)->createWindow(
            $folio,
            $request->string('code')->value(),
            $request->string('payer_type')->value(),
        );

        return $this->flashSuccess('Folio window created.');
    }

    public function storeRule(Request $request, Folio $folio): RedirectResponse
    {
        $this->ensureBranchAccess($folio->branch);

        $request->validate([
            'charge_category' => 'required|string|max:32',
            'target_window_id' => 'required|exists:folio_windows,id',
        ]);

        $window = FolioWindow::findOrFail($request->integer('target_window_id'));
        abort_unless($window->folio_id === $folio->id, 422, 'Window does not belong to this folio.');

        FolioRoutingRule::updateOrCreate(
            ['folio_id' => $folio->id, 'charge_category' => $request->string('charge_category')->value()],
            ['target_window_id' => $window->id, 'active' => true],
        );

        return $this->flashSuccess('Routing rule saved.');
    }

    public function split(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->ensureBranchAccess($transaction->folio->branch);

        $request->validate([
            'legs' => 'required|array|min:2',
            'legs.*.window_code' => 'nullable|string|max:16',
            'legs.*.window_id' => 'nullable|integer|exists:folio_windows,id',
            'legs.*.percent_bps' => 'nullable|integer|min:1|max:10000',
            'legs.*.amount_minor' => 'nullable|integer|min:1',
        ]);

        $input = $request->input('legs');

        if (! is_array($input)) {
            abort(422, 'Split legs must be an array.');
        }

        // Narrowed for the service contract (validation above already
        // enforced the shapes; this only satisfies precise typing).
        $legs = [];
        foreach ($input as $leg) {
            if (! is_array($leg)) {
                continue;
            }

            $entry = [];
            if (isset($leg['window_code']) && is_string($leg['window_code'])) {
                $entry['window_code'] = $leg['window_code'];
            }
            if (isset($leg['window_id']) && is_int($leg['window_id'])) {
                $entry['window_id'] = $leg['window_id'];
            }
            if (isset($leg['percent_bps']) && is_int($leg['percent_bps'])) {
                $entry['percent_bps'] = $leg['percent_bps'];
            }
            if (isset($leg['amount_minor']) && is_int($leg['amount_minor'])) {
                $entry['amount_minor'] = $leg['amount_minor'];
            }
            $legs[] = $entry;
        }

        (new FolioService)->splitTransaction($transaction, $legs);

        return $this->flashSuccess('Charge split across windows.');
    }

    public function transferToMaster(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->ensureBranchAccess($transaction->folio->branch);

        $request->validate(['master_folio_id' => 'required|exists:folios,id']);

        $master = Folio::findOrFail($request->integer('master_folio_id'));
        $this->ensureBranchAccess($master->branch);

        abort_unless($master->branch_id === $transaction->folio->branch_id, 422, 'Master folio is in another property.');

        (new FolioService)->transferToMaster($transaction, $master, $request->user()?->id);

        return $this->flashSuccess('Charge transferred to the master folio.');
    }
}
