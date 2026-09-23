<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Asset;
use App\Models\Branch;
use App\Models\MaintenanceTicket;
use App\Models\Room;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AssetController extends Controller
{
    use EnsuresBranchAccess;

    public function index(Branch $branch): Response
    {
        $this->ensureBranchAccess($branch);

        return Inertia::render('maintenance/Assets', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'assets' => Asset::forBranch($branch->id)
                ->with(['room', 'recentTickets'])
                ->orderBy('name')
                ->paginate(25)
                ->withQueryString(),
            'breaches' => MaintenanceTicket::forBranch($branch->id)
                ->active()
                ->whereNotNull('sla_due_at')
                ->where('sla_due_at', '<', now())
                ->with(['asset', 'room', 'assignee'])
                ->orderBy('sla_due_at')
                ->limit(50)
                ->get(),
            'rooms' => Room::forBranch($branch->id)->where('is_active', true)->orderBy('number')->get(['id', 'number']),
        ]);
    }

    public function store(Request $request, Branch $branch): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $request->validate([
            'name' => 'required|string|max:128',
            'category' => 'nullable|string|max:32',
            'room_id' => 'nullable|exists:rooms,id',
            'installed_on' => 'nullable|date',
            'every_days' => 'nullable|integer|min:1|max:3650',
            'checklist' => 'nullable|array|max:50',
            'checklist.*' => 'string|max:255',
        ]);

        $roomId = $request->input('room_id');
        $room = null;

        if ($roomId !== null && is_numeric($roomId)) {
            $room = Room::findOrFail((int) $roomId);
            abort_unless($room->branch_id === $branch->id, 403);
        }

        $schedule = null;
        if ($request->filled('every_days')) {
            $checklist = $request->input('checklist');
            $lines = [];
            if (is_array($checklist)) {
                foreach ($checklist as $item) {
                    if (is_string($item) && $item !== '') {
                        $lines[] = $item;
                    }
                }
            }
            $schedule = ['every_days' => $request->integer('every_days'), 'checklist' => $lines];
        }

        Asset::create([
            'branch_id' => $branch->id,
            'name' => $request->string('name')->value(),
            'category' => $request->string('category', 'other')->value(),
            'room_id' => $room?->id,
            'installed_on' => $request->string('installed_on')->value() ?: null,
            'pm_schedule' => $schedule,
        ]);

        return $this->flashSuccess('Asset registered.');
    }

    public function update(Request $request, Branch $branch, Asset $asset): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($asset->branch_id === $branch->id, 404);

        $request->validate([
            'name' => 'sometimes|string|max:128',
            'category' => 'sometimes|string|max:32',
            'installed_on' => 'nullable|date',
            'every_days' => 'nullable|integer|min:1|max:3650',
            'last_pm_at' => 'nullable|date',
        ]);

        $schedule = $asset->pm_schedule;
        $schedule = is_array($schedule) ? $schedule : [];

        if ($request->has('every_days')) {
            if ($request->filled('every_days')) {
                $schedule['every_days'] = $request->integer('every_days');
            } else {
                unset($schedule['every_days']);
            }
        }

        $asset->update([
            'name' => $request->input('name', $asset->name),
            'category' => $request->input('category', $asset->category),
            'installed_on' => $request->input('installed_on', $asset->installed_on?->toDateString()),
            'pm_schedule' => $schedule === [] ? null : $schedule,
            'last_pm_at' => $request->input('last_pm_at', $asset->last_pm_at?->toDateString()),
        ]);

        return $this->flashSuccess('Asset updated.');
    }

    public function destroy(Branch $branch, Asset $asset): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($asset->branch_id === $branch->id, 404);

        if ($asset->tickets()->active()->exists()) {
            return back()->withErrors(['asset' => 'Assets with open tickets cannot be deleted.']);
        }

        $asset->delete();

        return $this->flashSuccess('Asset deleted.');
    }
}
