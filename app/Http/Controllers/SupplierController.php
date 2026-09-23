<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Branch;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    use EnsuresBranchAccess;

    public function store(Request $request, Branch $branch): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $request->validate([
            'name' => 'required|string|max:128',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
        ]);

        Supplier::create([
            'branch_id' => $branch->id,
            'name' => $request->string('name')->value(),
            'email' => $request->string('email')->value() ?: null,
            'phone' => $request->string('phone')->value() ?: null,
        ]);

        return $this->flashSuccess('Supplier added.');
    }

    public function destroy(Branch $branch, Supplier $supplier): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($supplier->branch_id === $branch->id, 404);

        if ($supplier->purchaseOrders()->exists()) {
            return back()->withErrors(['supplier' => 'Suppliers with purchase orders cannot be deleted.']);
        }

        $supplier->delete();

        return $this->flashSuccess('Supplier deleted.');
    }
}
