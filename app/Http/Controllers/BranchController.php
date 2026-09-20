<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BranchController extends Controller
{
    public function switch(Request $request): RedirectResponse
    {
        $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
        ]);

        $user = Auth::user();
        abort_unless($user !== null, 401);

        $branch = Branch::findOrFail($request->integer('branch_id'));

        if (! $branch->is_active) {
            return back()->withErrors([
                'branch_id' => 'The selected property is not active.',
            ]);
        }

        if (! $user->hasAccessToBranch($branch)) {
            abort(403, 'You do not have access to this property.');
        }

        $user->update(['branch_id' => $branch->id]);

        session(['branch_id' => $branch->id]);

        return $this->flashSuccess('Switched to '.$branch->name);
    }
}
