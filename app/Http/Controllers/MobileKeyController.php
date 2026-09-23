<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Branch;
use App\Models\MobileKey;
use App\Models\Reservation;
use App\Services\MobileKeyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MobileKeyController extends Controller
{
    use EnsuresBranchAccess;

    public function store(Request $request, Branch $branch, MobileKeyService $keys): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $request->validate([
            'reservation_id' => 'required|integer',
            'device_id' => 'required|string|max:64',
        ]);

        $reservation = Reservation::findOrFail($request->integer('reservation_id'));
        abort_unless($reservation->branch_id === $branch->id, 404);

        $result = $keys->issue($reservation, $request->string('device_id')->value());

        return redirect()->route('connectivity.index', $branch)
            ->with('toast', ['type' => $result['degraded'] ? 'error' : 'success', 'message' => $result['degraded'] ? 'Vendor unreachable — plastic key fallback issued.' : 'Mobile key issued.']);
    }

    public function destroy(Branch $branch, MobileKey $key, MobileKeyService $keys): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($key->reservation->branch_id === $branch->id, 404);

        $keys->revoke($key);

        return redirect()->route('connectivity.index', $branch)
            ->with('toast', ['type' => 'success', 'message' => 'Mobile key revoked.']);
    }
}
