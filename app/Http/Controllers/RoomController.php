<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RoomController extends Controller
{
    public function index(Request $request): Response
    {
        $branchId = $request->user()->branch_id;

        $rooms = Room::forBranch($branchId)
            ->with(['roomType'])
            ->when($request->status, fn ($q, $status) => $q->forStatus($status))
            ->when($request->floor, fn ($q, $floor) => $q->forFloor($floor))
            ->orderBy('floor')
            ->orderBy('number')
            ->get();

        $roomTypes = RoomType::forBranch($branchId)->active()->get();

        $floors = Room::forBranch($branchId)
            ->whereNotNull('floor')
            ->distinct()
            ->pluck('floor')
            ->sort()
            ->values();

        return Inertia::render('rooms/Index', [
            'rooms' => $rooms,
            'roomTypes' => $roomTypes,
            'floors' => $floors,
            'filters' => $request->only(['status', 'floor']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'room_type_id' => 'required|exists:room_types,id',
            'number' => 'required|string|max:10',
            'floor' => 'nullable|string|max:10',
            'wing' => 'nullable|string|max:50',
            'is_accessible' => 'boolean',
            'is_smoking' => 'boolean',
            'notes' => 'nullable|string',
        ]);

        $validated['branch_id'] = $request->user()->branch_id;
        $validated['status'] = 'available';
        $validated['is_active'] = true;

        $room = Room::create($validated);

        return redirect()->route('rooms.index')
            ->with('success', 'Room '.$room->number.' created.');
    }

    public function update(Request $request, Room $room)
    {
        $validated = $request->validate([
            'room_type_id' => 'sometimes|exists:room_types,id',
            'floor' => 'nullable|string|max:10',
            'wing' => 'nullable|string|max:50',
            'is_accessible' => 'boolean',
            'is_smoking' => 'boolean',
            'is_active' => 'boolean',
            'notes' => 'nullable|string',
        ]);

        $room->update($validated);

        return back()->with('success', 'Room '.$room->number.' updated.');
    }

    public function updateStatus(Request $request, Room $room)
    {
        $validated = $request->validate([
            'status' => 'required|string|in:available,occupied,dirty,out_of_order',
        ]);

        $room->update(['status' => $validated['status']]);

        return back()->with('success', 'Room '.$room->number.' status updated.');
    }

    public function destroy(Room $room)
    {
        $room->delete();

        return redirect()->route('rooms.index')
            ->with('success', 'Room deleted.');
    }
}
