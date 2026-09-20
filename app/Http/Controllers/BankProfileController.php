<?php

namespace App\Http\Controllers;

use App\Models\BankProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BankProfileController extends Controller
{
    use Concerns\EnsuresBranchAccess;

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $branchId = (int) $user->branch_id;

        $bankProfiles = BankProfile::where('branch_id', $branchId)
            ->orderByDesc('is_default')
            ->orderBy('bank_name')
            ->get();

        return Inertia::render('settings/BankProfiles', [
            'bankProfiles' => $bankProfiles,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'bank_name' => 'required|string|max:255',
            'account_number' => 'required|string|max:50',
            'account_name' => 'required|string|max:255',
            'swift_code' => 'nullable|string|max:50',
            'sort_code' => 'nullable|string|max:50',
            'currency_code' => 'required|string|max:3',
            'is_default' => 'boolean',
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        if ($request->boolean('is_default')) {
            BankProfile::where('branch_id', $user->branch_id)->update(['is_default' => false]);
        }

        BankProfile::create([
            'branch_id' => $user->branch_id,
            'bank_name' => $request->string('bank_name')->value(),
            'account_number' => $request->string('account_number')->value(),
            'account_name' => $request->string('account_name')->value(),
            'swift_code' => $request->string('swift_code')->value() ?: null,
            'sort_code' => $request->string('sort_code')->value() ?: null,
            'currency_code' => $request->string('currency_code')->value(),
            'is_default' => $request->boolean('is_default'),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Bank profile created.']);

        return redirect()->route('bank-profiles.index');
    }

    public function update(Request $request, BankProfile $bankProfile): RedirectResponse
    {
        $this->ensureBranchAccess($bankProfile->branch);

        $request->validate([
            'bank_name' => 'sometimes|string|max:255',
            'account_number' => 'sometimes|string|max:50',
            'account_name' => 'sometimes|string|max:255',
            'swift_code' => 'nullable|string|max:50',
            'sort_code' => 'nullable|string|max:50',
            'currency_code' => 'sometimes|string|max:3',
            'is_default' => 'boolean',
        ]);

        if ($request->boolean('is_default')) {
            BankProfile::where('branch_id', $bankProfile->branch_id)->update(['is_default' => false]);
        }

        $bankProfile->update([
            'bank_name' => $request->input('bank_name'),
            'account_number' => $request->input('account_number'),
            'account_name' => $request->input('account_name'),
            'swift_code' => $request->input('swift_code'),
            'sort_code' => $request->input('sort_code'),
            'currency_code' => $request->input('currency_code'),
            'is_default' => $request->boolean('is_default'),
        ]);

        return $this->flashSuccess('Bank profile updated.');
    }

    public function destroy(BankProfile $bankProfile): RedirectResponse
    {
        $this->ensureBranchAccess($bankProfile->branch);

        $bankProfile->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Bank profile deleted.']);

        return redirect()->route('bank-profiles.index');
    }
}
