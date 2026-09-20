<?php

namespace App\Http\Controllers;

use App\Models\CityLedgerAccount;
use App\Models\CityLedgerTransaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CityLedgerController extends Controller
{
    use Concerns\EnsuresBranchAccess;

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $branchId = (int) $user->branch_id;

        $accounts = CityLedgerAccount::where('branch_id', $branchId)
            ->orderBy('company_name')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('city-ledger/Index', [
            'cityLedgerAccounts' => $accounts,
        ]);
    }

    public function show(CityLedgerAccount $cityLedgerAccount): Response
    {
        $user = request()->user();
        abort_unless($user !== null, 401);

        $this->ensureBranchAccess($cityLedgerAccount->branch);

        return Inertia::render('city-ledger/Show', [
            'cityLedgerAccount' => $cityLedgerAccount->load('transactions.folio'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'company_name' => 'required|string|max:255',
            'contact_name' => 'required|string|max:255',
            'email' => 'required|email',
            'phone' => 'nullable|string|max:50',
            'credit_limit' => 'required|integer|min:0',
            'payment_terms_days' => 'required|integer|min:1',
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        CityLedgerAccount::create([
            'branch_id' => $user->branch_id,
            'currency_code' => $user->currentBranch->currency_code,
            'company_name' => $request->string('company_name')->value(),
            'contact_name' => $request->string('contact_name')->value(),
            'email' => $request->string('email')->value(),
            'phone' => $request->string('phone')->value() ?: null,
            'credit_limit' => $request->integer('credit_limit'),
            'payment_terms_days' => $request->integer('payment_terms_days'),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'City ledger account created.']);

        return redirect()->route('city-ledger.index');
    }

    public function update(Request $request, CityLedgerAccount $cityLedgerAccount): RedirectResponse
    {
        $this->ensureBranchAccess($cityLedgerAccount->branch);

        $request->validate([
            'company_name' => 'sometimes|string|max:255',
            'contact_name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email',
            'credit_limit' => 'sometimes|integer|min:0',
            'payment_terms_days' => 'sometimes|integer|min:1',
            'is_active' => 'boolean',
        ]);

        $cityLedgerAccount->update([
            'company_name' => $request->input('company_name'),
            'contact_name' => $request->input('contact_name'),
            'email' => $request->input('email'),
            'credit_limit' => $request->input('credit_limit'),
            'payment_terms_days' => $request->input('payment_terms_days'),
            'is_active' => $request->boolean('is_active'),
        ]);

        return $this->flashSuccess('City ledger account updated.');
    }

    public function charge(Request $request, CityLedgerAccount $cityLedgerAccount): RedirectResponse
    {
        $this->ensureBranchAccess($cityLedgerAccount->branch);

        $request->validate([
            'amount' => 'required|integer|min:1',
            'folio_id' => 'nullable|integer|exists:folios,id',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $amount = $request->integer('amount');

        if ($cityLedgerAccount->credit_limit > 0
            && ($cityLedgerAccount->balance_owing + $amount) > $cityLedgerAccount->credit_limit) {
            return back()->withErrors([
                'amount' => 'Credit limit exceeded. Current balance: $'.number_format($cityLedgerAccount->balance_owing / 100, 2)
                    .'. Limit: $'.number_format($cityLedgerAccount->credit_limit / 100, 2).'.',
            ]);
        }

        $cityLedgerAccount->update([
            'balance_owing' => $cityLedgerAccount->balance_owing + $amount,
        ]);

        CityLedgerTransaction::create([
            'city_ledger_account_id' => $cityLedgerAccount->id,
            'currency_code' => $cityLedgerAccount->currency_code,
            'folio_id' => $request->filled('folio_id') ? $request->integer('folio_id') : null,
            'type' => 'debit',
            'amount' => $amount,
            'reference' => $request->string('reference')->value() ?: null,
            'notes' => $request->string('notes')->value() ?: null,
        ]);

        return $this->flashSuccess('Charge posted.');
    }

    public function pay(Request $request, CityLedgerAccount $cityLedgerAccount): RedirectResponse
    {
        $this->ensureBranchAccess($cityLedgerAccount->branch);

        $request->validate([
            'amount' => 'required|integer|min:1',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $cityLedgerAccount->update([
            'balance_owing' => max(0, $cityLedgerAccount->balance_owing - $request->integer('amount')),
        ]);

        CityLedgerTransaction::create([
            'city_ledger_account_id' => $cityLedgerAccount->id,
            'currency_code' => $cityLedgerAccount->currency_code,
            'type' => 'payment',
            'amount' => $request->integer('amount'),
            'reference' => $request->string('reference')->value() ?: null,
            'notes' => $request->string('notes')->value() ?: null,
        ]);

        return $this->flashSuccess('Payment recorded.');
    }

    public function statement(CityLedgerAccount $cityLedgerAccount): Response
    {
        $user = request()->user();
        abort_unless($user !== null, 401);

        $this->ensureBranchAccess($cityLedgerAccount->branch);

        $transactions = $cityLedgerAccount->transactions()
            ->with('folio')
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('city-ledger/Statement', [
            'cityLedgerAccount' => $cityLedgerAccount,
            'transactions' => $transactions,
        ]);
    }
}
