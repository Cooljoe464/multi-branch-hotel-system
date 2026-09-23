<?php

namespace App\Http\Controllers;

use App\Exceptions\AvailabilityException;
use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Asset;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\MaintenanceTicket;
use App\Models\Room;
use App\Models\User;
use App\Services\MaintenanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WorkOrderController extends Controller
{
    use EnsuresBranchAccess;

    public function store(Request $request, Branch $branch): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category' => 'nullable|string|max:32',
            'priority' => 'nullable|string|in:low,normal,high,urgent',
            'room_id' => 'nullable|exists:rooms,id',
            'asset_id' => 'nullable|exists:assets,id',
        ]);

        $roomId = $request->input('room_id');
        $room = null;

        if ($roomId !== null && is_numeric($roomId)) {
            $room = Room::findOrFail((int) $roomId);
            abort_unless($room->branch_id === $branch->id, 403);
        }

        $assetId = $request->input('asset_id');
        $asset = null;

        if ($assetId !== null && is_numeric($assetId)) {
            $asset = Asset::findOrFail((int) $assetId);
            abort_unless($asset->branch_id === $branch->id, 403);
        }

        $description = $request->input('description');

        (new MaintenanceService)->raise($branch, [
            'title' => $request->string('title')->value(),
            'description' => is_string($description) ? $description : null,
            'category' => $request->string('category', 'other')->value(),
            'priority' => $request->string('priority', 'normal')->value(),
            'room_id' => $room?->id,
            'asset_id' => $asset?->id,
        ], $user);

        return $this->flashSuccess('Work order raised with its SLA clock running.');
    }

    public function assign(Request $request, Branch $branch, MaintenanceTicket $ticket): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($ticket->branch_id === $branch->id, 404);

        $request->validate([
            'assignee_id' => 'required|exists:users,id',
        ]);

        $assignee = User::findOrFail($request->integer('assignee_id'));

        try {
            (new MaintenanceService)->assign($ticket, $assignee, $request->user());
        } catch (AvailabilityException $e) {
            return back()->withErrors(['assignee_id' => $e->getMessage()]);
        }

        return $this->flashSuccess('Work order assigned.');
    }

    public function resolve(Request $request, Branch $branch, MaintenanceTicket $ticket): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($ticket->branch_id === $branch->id, 404);

        $request->validate([
            'notes' => 'nullable|string|max:2000',
            'parts' => 'nullable|array|max:50',
            'parts.*.inventory_item_id' => 'required|integer|exists:inventory_items,id',
            'parts.*.qty' => 'required|numeric|min:0.01',
        ]);

        $raw = $request->input('parts');
        $parts = [];
        if (is_array($raw)) {
            foreach ($raw as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $itemId = $row['inventory_item_id'] ?? null;
                $qty = $row['qty'] ?? null;

                if (! is_int($itemId) || (! is_int($qty) && ! is_float($qty))) {
                    continue;
                }

                $item = InventoryItem::find($itemId);
                abort_unless($item && $item->branch_id === $branch->id, 422, 'Parts must belong to this property.');

                $parts[] = ['inventory_item_id' => $itemId, 'qty' => $qty];
            }
        }

        $notes = $request->input('notes');

        try {
            (new MaintenanceService)->resolve($ticket, $parts, is_string($notes) ? $notes : null, $request->user());
        } catch (AvailabilityException $e) {
            return back()->withErrors(['ticket' => $e->getMessage()]);
        }

        return $this->flashSuccess('Work order resolved; parts cost posted.');
    }

    public function slaCheck(Branch $branch, MaintenanceTicket $ticket): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($ticket->branch_id === $branch->id, 404);

        $breached = (new MaintenanceService)->checkSla($ticket);

        return $this->flashSuccess($breached ? 'SLA breach escalated.' : 'SLA clock healthy or already escalated.');
    }
}
