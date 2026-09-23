<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Branch;
use App\Models\LostFoundItem;
use App\Models\Room;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LostFoundController extends Controller
{
    use EnsuresBranchAccess;

    public function index(Branch $branch): Response
    {
        $this->ensureBranchAccess($branch);

        return Inertia::render('housekeeping/LostFound', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'items' => LostFoundItem::forBranch($branch->id)
                ->with(['room', 'logger'])
                ->orderByDesc('id')
                ->paginate(25)
                ->withQueryString(),
        ]);
    }

    public function store(Request $request, Branch $branch): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $request->validate([
            'room_id' => 'nullable|exists:rooms,id',
            'description' => 'required|string|max:256',
        ]);

        $roomId = $request->input('room_id');
        $room = null;

        if ($roomId !== null && is_numeric($roomId)) {
            $room = Room::findOrFail((int) $roomId);
            abort_unless($room->branch_id === $branch->id, 403);
        }

        LostFoundItem::create([
            'branch_id' => $branch->id,
            'room_id' => $room?->id,
            'description' => $request->string('description')->value(),
            'status' => LostFoundItem::STATUS_LOGGED,
            'logged_by' => $request->user()?->id,
        ]);

        return $this->flashSuccess('Item logged.');
    }

    public function claim(Request $request, Branch $branch, LostFoundItem $item): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($item->branch_id === $branch->id, 404);

        if ($item->status !== LostFoundItem::STATUS_LOGGED) {
            return back()->withErrors(['item' => 'Only logged items can be claimed.']);
        }

        $request->validate([
            'collected_by' => 'required|string|max:255',
        ]);

        $item->update([
            'status' => LostFoundItem::STATUS_CLAIMED,
            'claim' => [
                'collected_by' => $request->string('collected_by')->value(),
                'claimed_at' => now()->toDateTimeString(),
                'handled_by' => $request->user()?->id,
            ],
        ]);

        return $this->flashSuccess('Item claimed.');
    }

    public function dispose(Branch $branch, LostFoundItem $item): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($item->branch_id === $branch->id, 404);

        if ($item->status !== LostFoundItem::STATUS_LOGGED) {
            return back()->withErrors(['item' => 'Only logged items can be disposed.']);
        }

        $item->update(['status' => LostFoundItem::STATUS_DISPOSED]);

        return $this->flashSuccess('Item disposed.');
    }
}
