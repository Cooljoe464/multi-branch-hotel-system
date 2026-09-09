<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceTicket;
use App\Models\Room;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MaintenanceController extends Controller
{
    public function index(Request $request): Response
    {
        $branchId = $request->user()->branch_id;

        $tickets = MaintenanceTicket::forBranch($branchId)
            ->with(['room', 'reporter', 'assignee'])
            ->when($request->status, fn ($q, $status) => $q->forStatus($status))
            ->when($request->category, fn ($q, $category) => $q->forCategory($category))
            ->when($request->priority, fn ($q, $priority) => $q->forPriority($priority))
            ->orderBy('priority', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

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
        $branchId = $request->user()->branch_id;

        $rooms = Room::forBranch($branchId)
            ->where('is_active', true)
            ->orderBy('number')
            ->get();

        return Inertia::render('maintenance/Create', [
            'rooms' => $rooms,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'room_id' => 'nullable|exists:rooms,id',
            'category' => 'required|string|in:plumbing,electrical,hvac,furniture,appliance,structural,other',
            'priority' => 'required|string|in:low,normal,high,urgent',
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'is_room_locked' => 'boolean',
            'estimated_cost' => 'nullable|integer|min:0',
        ]);

        $validated['branch_id'] = $request->user()->branch_id;
        $validated['reported_by'] = $request->user()->id;
        $validated['status'] = 'open';

        $ticket = MaintenanceTicket::create($validated);

        if (! empty($validated['is_room_locked']) && $validated['is_room_locked'] && $ticket->room) {
            $ticket->lockRoom();
        }

        return redirect()->route('maintenance.show', $ticket)
            ->with('success', 'Ticket '.$ticket->ticket_number.' created.');
    }

    public function show(MaintenanceTicket $ticket): Response
    {
        $ticket->load(['room', 'reporter', 'assignee']);

        return Inertia::render('maintenance/Show', [
            'ticket' => $ticket,
        ]);
    }

    public function update(Request $request, MaintenanceTicket $ticket)
    {
        $validated = $request->validate([
            'category' => 'sometimes|string|in:plumbing,electrical,hvac,furniture,appliance,structural,other',
            'priority' => 'sometimes|string|in:low,normal,high,urgent',
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'assigned_to' => 'nullable|exists:users,id',
            'estimated_cost' => 'nullable|integer|min:0',
        ]);

        $ticket->update($validated);

        return back()->with('success', 'Ticket updated.');
    }

    public function start(MaintenanceTicket $ticket)
    {
        if ($ticket->status !== 'open') {
            return back()->withErrors(['status' => 'Ticket cannot be started.']);
        }

        $ticket->start();

        return back()->with('success', 'Ticket started.');
    }

    public function complete(Request $request, MaintenanceTicket $ticket)
    {
        if ($ticket->status !== 'in_progress') {
            return back()->withErrors(['status' => 'Ticket is not in progress.']);
        }

        $validated = $request->validate([
            'resolution_notes' => 'nullable|string',
            'actual_cost' => 'nullable|integer|min:0',
        ]);

        $ticket->complete(
            $validated['resolution_notes'] ?? null,
            $validated['actual_cost'] ?? null
        );

        return back()->with('success', 'Ticket completed.');
    }

    public function lockRoom(Request $request, MaintenanceTicket $ticket)
    {
        $ticket->lockRoom();

        return back()->with('success', 'Room locked for maintenance.');
    }

    public function unlockRoom(MaintenanceTicket $ticket)
    {
        $ticket->unlockRoom();

        return back()->with('success', 'Room unlocked.');
    }

    public function destroy(MaintenanceTicket $ticket)
    {
        if ($ticket->status === 'in_progress') {
            return back()->withErrors(['status' => 'Cannot delete an in-progress ticket.']);
        }

        if ($ticket->is_room_locked) {
            $ticket->unlockRoom();
        }

        $ticket->delete();

        return redirect()->route('maintenance.index')
            ->with('success', 'Ticket deleted.');
    }
}
