<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Branch;
use App\Models\PosModifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PosModifierController extends Controller
{
    use EnsuresBranchAccess;

    public function store(Request $request, Branch $branch): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $request->validate([
            'name' => 'required|string|max:64',
            'price_delta_minor' => 'required|integer',
        ]);

        PosModifier::create([
            'branch_id' => $branch->id,
            'name' => $request->string('name')->value(),
            'price_delta_minor' => $request->integer('price_delta_minor'),
            'active' => true,
        ]);

        return $this->flashSuccess('Modifier added.');
    }

    public function destroy(Branch $branch, PosModifier $modifier): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($modifier->branch_id === $branch->id, 404);

        $modifier->delete();

        return $this->flashSuccess('Modifier deleted.');
    }
}
