<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BranchController extends Controller
{
    public function switch(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
        ]);

        $user = Auth::user();
        $branch = Branch::findOrFail($validated['branch_id']);

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

        return back()->with('success', 'Switched to '.$branch->name);
    }
}
