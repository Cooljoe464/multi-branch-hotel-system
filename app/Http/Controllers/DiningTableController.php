<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\DiningTable;
use App\Models\Outlet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DiningTableController extends Controller
{
    use EnsuresBranchAccess;

    public function store(Request $request, Outlet $outlet): RedirectResponse
    {
        $this->ensureBranchAccess($outlet->branch);

        $request->validate([
            'code' => 'required|string|max:16',
            'shape' => 'nullable|string|in:square,round,rectangle,bar',
            'seats' => 'nullable|integer|min:1|max:100',
            'position' => 'nullable|array',
        ]);

        $position = $request->input('position');

        DiningTable::create([
            'outlet_id' => $outlet->id,
            'branch_id' => $outlet->branch_id,
            'code' => $request->string('code')->value(),
            'shape' => $request->string('shape', 'square')->value(),
            'seats' => $request->integer('seats', 2),
            'position' => is_array($position) ? $position : null,
            'status' => DiningTable::STATUS_FREE,
        ]);

        return $this->flashSuccess("Table {$request->string('code')->value()} added to the floor plan.");
    }

    public function destroy(Outlet $outlet, DiningTable $table): RedirectResponse
    {
        $this->ensureBranchAccess($outlet->branch);
        abort_unless($table->outlet_id === $outlet->id, 404);

        if ($table->status !== DiningTable::STATUS_FREE) {
            return back()->withErrors(['table' => 'Seated tables cannot be deleted.']);
        }

        $table->delete();

        return $this->flashSuccess('Table removed.');
    }
}
