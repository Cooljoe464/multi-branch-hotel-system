<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Models\RoomType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RoomController extends Controller
{
    use Concerns\EnsuresBranchAccess;

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $branchId = (int) $user->branch_id;

        $rooms = Room::forBranch($branchId)
            ->with(['roomType'])
            ->when($request->filled('status'), fn ($q) => $q->forStatus($request->string('status')->value()))
            ->when($request->filled('floor'), fn ($q) => $q->forFloor($request->string('floor')->value()))
            ->orderBy('floor')
            ->orderBy('number')
            ->paginate(25)
            ->withQueryString();

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

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'room_type_id' => 'required|exists:room_types,id',
            'number' => 'required|string|max:10',
            'floor' => 'nullable|string|max:10',
            'wing' => 'nullable|string|max:50',
            'is_accessible' => 'boolean',
            'is_smoking' => 'boolean',
            'notes' => 'nullable|string',
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $roomType = RoomType::findOrFail($request->integer('room_type_id'));

        if ($roomType->branch_id !== (int) $user->branch_id && ! $user->is_global_admin) {
            abort(403, 'The selected room type does not belong to this property.');
        }

        $room = Room::create([
            'branch_id' => $user->branch_id,
            'room_type_id' => $roomType->id,
            'number' => $request->string('number')->value(),
            'floor' => $request->string('floor')->value() ?: null,
            'wing' => $request->string('wing')->value() ?: null,
            'is_accessible' => $request->boolean('is_accessible'),
            'is_smoking' => $request->boolean('is_smoking'),
            'notes' => $request->string('notes')->value() ?: null,
            'status' => 'available',
            'is_active' => true,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Room '.$room->number.' created.']);

        return redirect()->route('rooms.index');
    }

    public function update(Request $request, Room $room): RedirectResponse
    {
        $this->ensureBranchAccess($room->branch);

        $request->validate([
            'room_type_id' => 'sometimes|exists:room_types,id',
            'floor' => 'nullable|string|max:10',
            'wing' => 'nullable|string|max:50',
            'is_accessible' => 'boolean',
            'is_smoking' => 'boolean',
            'is_active' => 'boolean',
            'notes' => 'nullable|string',
        ]);

        $data = [];
        if ($request->has('room_type_id')) {
            $data['room_type_id'] = $request->integer('room_type_id');
        }
        if ($request->has('floor')) {
            $data['floor'] = $request->string('floor')->value() ?: null;
        }
        if ($request->has('wing')) {
            $data['wing'] = $request->string('wing')->value() ?: null;
        }
        if ($request->has('is_accessible')) {
            $data['is_accessible'] = $request->boolean('is_accessible');
        }
        if ($request->has('is_smoking')) {
            $data['is_smoking'] = $request->boolean('is_smoking');
        }
        if ($request->has('is_active')) {
            $data['is_active'] = $request->boolean('is_active');
        }
        if ($request->has('notes')) {
            $data['notes'] = $request->string('notes')->value() ?: null;
        }

        $room->update($data);

        return $this->flashSuccess('Room '.$room->number.' updated.');
    }

    public function updateStatus(Request $request, Room $room): RedirectResponse
    {
        $this->ensureBranchAccess($room->branch);

        $request->validate([
            'status' => 'required|string|in:available,occupied,dirty,out_of_order',
            'version' => 'nullable|integer|min:1',
        ]);

        if ($request->has('version')) {
            $room->saveWithVersion(
                ['status' => $request->string('status')->value()],
                $request->integer('version'),
            );
        } else {
            $room->update(['status' => $request->string('status')->value()]);
        }

        return $this->flashSuccess('Room '.$room->number.' status updated.');
    }

    public function destroy(Room $room): RedirectResponse
    {
        $this->ensureBranchAccess($room->branch);

        $room->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Room deleted.']);

        return redirect()->route('rooms.index');
    }
}
