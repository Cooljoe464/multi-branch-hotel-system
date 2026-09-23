<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceTicket;
use App\Models\Room;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MaintenanceController extends Controller
{
    use Concerns\EnsuresBranchAccess;

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $branchId = (int) $user->branch_id;

        $tickets = MaintenanceTicket::forBranch($branchId)
            ->with(['room', 'reporter', 'assignee'])
            ->when($request->filled('status'), fn ($q) => $q->forStatus($request->string('status')->value()))
            ->when($request->filled('category'), fn ($q) => $q->forCategory($request->string('category')->value()))
            ->when($request->filled('priority'), fn ($q) => $q->forPriority($request->string('priority')->value()))
            ->orderBy('priority', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate(25)
            ->withQueryString();

        $lockedRooms = MaintenanceTicket::forBranch($branchId)
            ->lockedRooms()
            ->with('room')
            ->get();

        $users = User::where('branch_id', $branchId)->get();

        $stats = [
            'open' => MaintenanceTicket::forBranch($branchId)->forStatus('open')->count(),
            'in_progress' => MaintenanceTicket::forBranch($branchId)->forStatus('in_progress')->count(),
            'locked_rooms' => $lockedRooms->count(),
        ];

        return Inertia::render('maintenance/Index', [
            'tickets' => $tickets,
            'lockedRooms' => $lockedRooms,
            'users' => $users,
            'stats' => $stats,
            'filters' => $request->only(['status', 'category', 'priority']),
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $branchId = (int) $user->branch_id;

        $rooms = Room::forBranch($branchId)
            ->where('is_active', true)
            ->orderBy('number')
            ->get();

        return Inertia::render('maintenance/Create', [
            'rooms' => $rooms,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $roomId = $request->string('room_id')->value();
        $request->merge(['room_id' => ($roomId === '' || $roomId === 'none') ? null : $roomId]);

        if ($request->filled('room_id')) {
            $room = Room::find($request->integer('room_id') ?: null);

            if (! $room) {
                abort(404);
            }

            $this->ensureBranchAccess($room->branch);
        }

        $request->validate([
            'room_id' => 'nullable|exists:rooms,id',
            'category' => 'required|string|in:plumbing,electrical,hvac,furniture,appliance,structural,other',
            'priority' => 'required|string|in:low,normal,high,urgent',
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'is_room_locked' => 'boolean',
            'estimated_cost' => 'nullable|integer|min:0',
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $currentBranch = $user->currentBranch;
        abort_unless($currentBranch !== null, 422, 'No active property.');

        $ticket = MaintenanceTicket::create([
            'branch_id' => $user->branch_id,
            'currency_code' => $currentBranch->currency_code,
            'room_id' => $request->filled('room_id') ? $request->integer('room_id') : null,
            'reported_by' => $user->id,
            'category' => $request->string('category')->value(),
            'priority' => $request->string('priority')->value(),
            'title' => $request->string('title')->value(),
            'description' => $request->string('description')->value(),
            'estimated_cost' => $request->filled('estimated_cost') ? $request->integer('estimated_cost') : null,
            'status' => 'open',
        ]);

        if ($request->boolean('is_room_locked') && $ticket->room) {
            $ticket->lockRoom();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Ticket '.$ticket->ticket_number.' created.']);

        return redirect()->route('maintenance.show', $ticket);
    }

    public function show(MaintenanceTicket $ticket): Response
    {
        $this->ensureBranchAccess($ticket->branch);

        $ticket->load(['room', 'reporter', 'assignee']);

        return Inertia::render('maintenance/Show', [
            'ticket' => $ticket,
        ]);
    }

    public function update(Request $request, MaintenanceTicket $ticket): RedirectResponse
    {
        $this->ensureBranchAccess($ticket->branch);

        $request->validate([
            'category' => 'sometimes|string|in:plumbing,electrical,hvac,furniture,appliance,structural,other',
            'priority' => 'sometimes|string|in:low,normal,high,urgent',
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'assigned_to' => 'nullable|exists:users,id',
            'estimated_cost' => 'nullable|integer|min:0',
        ]);

        $data = [];
        if ($request->has('category')) {
            $data['category'] = $request->string('category')->value();
        }
        if ($request->has('priority')) {
            $data['priority'] = $request->string('priority')->value();
        }
        if ($request->has('title')) {
            $data['title'] = $request->string('title')->value();
        }
        if ($request->has('description')) {
            $data['description'] = $request->string('description')->value();
        }
        if ($request->has('assigned_to')) {
            $data['assigned_to'] = $request->integer('assigned_to') ?: null;
        }
        if ($request->has('estimated_cost')) {
            $data['estimated_cost'] = $request->integer('estimated_cost') ?: null;
        }

        $ticket->update($data);

        return $this->flashSuccess('Ticket updated.');
    }

    public function start(MaintenanceTicket $ticket): RedirectResponse
    {
        $this->ensureBranchAccess($ticket->branch);

        if ($ticket->status !== 'open') {
            return back()->withErrors(['status' => 'Ticket cannot be started.']);
        }

        $ticket->start();

        return $this->flashSuccess('Ticket started.');
    }

    public function complete(Request $request, MaintenanceTicket $ticket): RedirectResponse
    {
        $this->ensureBranchAccess($ticket->branch);

        if ($ticket->status !== 'in_progress') {
            return back()->withErrors(['status' => 'Ticket is not in progress.']);
        }

        $request->validate([
            'resolution_notes' => 'nullable|string',
            'actual_cost' => 'nullable|integer|min:0',
        ]);

        $notes = $request->string('resolution_notes')->value();

        $ticket->complete(
            $notes !== '' ? $notes : null,
            $request->filled('actual_cost') ? $request->integer('actual_cost') : null
        );

        return $this->flashSuccess('Ticket completed.');
    }

    public function lockRoom(Request $request, MaintenanceTicket $ticket): RedirectResponse
    {
        $this->ensureBranchAccess($ticket->branch);

        $user = $request->user();
        abort_unless($user !== null, 401);

        if (! $user->can('door_lock.manage')) {
            abort(403, 'You do not have permission to lock rooms.');
        }

        $ticket->lockRoom();

        return $this->flashSuccess('Room locked for maintenance.');
    }

    public function unlockRoom(MaintenanceTicket $ticket): RedirectResponse
    {
        $this->ensureBranchAccess($ticket->branch);

        $user = request()->user();
        abort_unless($user !== null, 401);

        if (! $user->can('door_lock.manage')) {
            abort(403, 'You do not have permission to unlock rooms.');
        }

        $ticket->unlockRoom();

        return $this->flashSuccess('Room unlocked.');
    }

    public function destroy(MaintenanceTicket $ticket): RedirectResponse
    {
        $this->ensureBranchAccess($ticket->branch);

        if ($ticket->status === 'in_progress') {
            return back()->withErrors(['status' => 'Cannot delete an in-progress ticket.']);
        }

        if ($ticket->is_room_locked) {
            $ticket->unlockRoom();
        }

        $ticket->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Ticket deleted.']);

        return redirect()->route('maintenance.index');
    }
}
