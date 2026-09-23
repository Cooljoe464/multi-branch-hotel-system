<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Branch;
use App\Models\FunctionSpace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FunctionSpaceController extends Controller
{
    use EnsuresBranchAccess;

    public function store(Request $request, Branch $branch): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $request->validate([
            'name' => 'required|string|max:64',
            'capacity' => 'nullable|integer|min:0|max:10000',
        ]);

        FunctionSpace::create([
            'branch_id' => $branch->id,
            'name' => $request->string('name')->value(),
            'capacity' => $request->input('capacity'),
        ]);

        return $this->flashSuccess('Function space added.');
    }

    public function destroy(Branch $branch, FunctionSpace $space): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($space->branch_id === $branch->id, 404);

        if ($space->beos()->exists()) {
            return back()->withErrors(['space' => 'Spaces with BEOs cannot be deleted.']);
        }

        $space->delete();

        return $this->flashSuccess('Function space deleted.');
    }
}
