<?php

namespace App\Http\Controllers;

use App\Exceptions\AvailabilityException;
use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Branch;
use App\Models\Room;
use App\Services\HousekeepingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MinibarController extends Controller
{
    use EnsuresBranchAccess;

    public function store(Request $request, Branch $branch, Room $room): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($room->branch_id === $branch->id, 404);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $request->validate([
            'items' => 'required|array|min:1|max:20',
            'items.*.name' => 'required|string|max:128',
            'items.*.qty' => 'required|integer|min:1|max:50',
            'items.*.unit_price_minor' => 'required|integer|min:0',
        ]);

        $raw = $request->input('items');
        $items = [];
        if (is_array($raw)) {
            foreach ($raw as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $clean = [];
                foreach ($row as $key => $value) {
                    if (is_string($key)) {
                        $clean[$key] = $value;
                    }
                }
                $items[] = $clean;
            }
        }

        $key = $request->header('X-Idempotency-Key');
        $key = is_string($key) && trim($key) !== '' ? trim($key) : null;

        abort_unless($key !== null, 422, 'Minibar postings require an X-Idempotency-Key header.');

        try {
            $charge = (new HousekeepingService)->postMinibar($room, $items, $user, $key);
        } catch (AvailabilityException $e) {
            return back()->withErrors(['items' => $e->getMessage()]);
        }

        return $this->flashSuccess("Minibar posted: {$charge->amount} minor to the folio.");
    }
}
