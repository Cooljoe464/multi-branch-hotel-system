<?php

namespace App\Http\Controllers;

use App\Models\GroupLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GroupLedgerController extends Controller
{
    use Concerns\EnsuresBranchAccess;

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $branchId = (int) $user->branch_id;

        $ledgers = GroupLedger::where('branch_id', $branchId)
            ->orderByDesc('business_date')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('analytics/GroupLedger', [
            'groupLedgers' => $ledgers,
        ]);
    }

    public function show(GroupLedger $groupLedger): Response
    {
        $this->ensureBranchAccess($groupLedger->branch);

        return Inertia::render('analytics/GroupLedgerShow', [
            'groupLedger' => $groupLedger,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $request->validate([
            'business_date' => 'required|date',
            'total_room_revenue' => 'required|integer|min:0',
            'total_pos_revenue' => 'required|integer|min:0',
            'total_tax' => 'required|integer|min:0',
            'total_payments' => 'required|integer|min:0',
            'net_revenue' => 'required|integer|min:0',
            'currency_code' => 'required|string|size:3',
            'exchange_rate_to_group' => 'required|numeric',
        ]);

        GroupLedger::create([
            'branch_id' => $user->branch_id,
            'business_date' => $request->string('business_date')->value(),
            'total_room_revenue' => $request->integer('total_room_revenue'),
            'total_pos_revenue' => $request->integer('total_pos_revenue'),
            'total_tax' => $request->integer('total_tax'),
            'total_payments' => $request->integer('total_payments'),
            'net_revenue' => $request->integer('net_revenue'),
            'currency_code' => $request->string('currency_code')->value(),
            'exchange_rate_to_group' => $request->float('exchange_rate_to_group'),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Group ledger created.']);

        return redirect()->route('group-ledger.index');
    }

    public function update(Request $request, GroupLedger $groupLedger): RedirectResponse
    {
        $this->ensureBranchAccess($groupLedger->branch);

        $request->validate([
            'total_room_revenue' => 'sometimes|integer|min:0',
            'total_pos_revenue' => 'sometimes|integer|min:0',
            'total_tax' => 'sometimes|integer|min:0',
            'total_payments' => 'sometimes|integer|min:0',
            'net_revenue' => 'sometimes|integer|min:0',
            'currency_code' => 'sometimes|string|size:3',
            'exchange_rate_to_group' => 'sometimes|numeric|min:0',
        ]);

        $data = array_filter([
            'total_room_revenue' => $request->input('total_room_revenue'),
            'total_pos_revenue' => $request->input('total_pos_revenue'),
            'total_tax' => $request->input('total_tax'),
            'total_payments' => $request->input('total_payments'),
            'net_revenue' => $request->input('net_revenue'),
            'currency_code' => $request->input('currency_code'),
            'exchange_rate_to_group' => $request->input('exchange_rate_to_group'),
        ], fn ($v) => $v !== null);

        $groupLedger->update($data);

        return $this->flashSuccess('Group ledger updated.');
    }

    public function destroy(GroupLedger $groupLedger): RedirectResponse
    {
        $this->ensureBranchAccess($groupLedger->branch);

        $groupLedger->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Group ledger deleted.']);

        return redirect()->route('group-ledger.index');
    }
}
