<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HousekeepingController extends Controller
{
    use Concerns\EnsuresBranchAccess;

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $branchId = (int) $user->branch_id;

        $tasks = Task::forBranch($branchId)
            ->with(['room', 'assignee'])
            ->when($request->filled('status'), fn ($q) => $q->forStatus($request->string('status')->value()))
            ->when($request->filled('type'), fn ($q) => $q->forType($request->string('type')->value()))
            ->when($request->filled('assigned_to'), fn ($q) => $q->forUser($request->integer('assigned_to')))
            ->orderBy('priority', 'desc')
            ->orderBy('created_at', 'asc')
            ->paginate(25)
            ->withQueryString();

        $housekeepers = User::where('branch_id', $branchId)
            ->orWhereHas('roles', fn ($q) => $q->where('name', 'Housekeeper'))
            ->get();

        $stats = [
            'pending' => Task::forBranch($branchId)->forStatus('pending')->count(),
            'in_progress' => Task::forBranch($branchId)->forStatus('in_progress')->count(),
            'completed_today' => Task::forBranch($branchId)
                ->where('status', 'completed')
                ->whereDate('completed_at', today())
                ->count(),
        ];

        return Inertia::render('housekeeping/Index', [
            'tasks' => $tasks,
            'housekeepers' => $housekeepers,
            'stats' => $stats,
            'filters' => $request->only(['status', 'type', 'assigned_to']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $room = Room::find($request->integer('room_id') ?: null);

        if (! $room) {
            abort(404);
        }

        $this->ensureBranchAccess($room->branch);

        $request->validate([
            'room_id' => 'required|exists:rooms,id',
            'type' => 'required|string|in:cleaning,deep_clean,turnover,inspection,laundry,maintenance_request',
            'priority' => 'required|string|in:low,normal,high,urgent',
            'description' => 'nullable|string',
            'assigned_to' => 'nullable|exists:users,id',
            'estimated_minutes' => 'nullable|integer|min:5|max:480',
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $task = Task::create([
            'branch_id' => $user->branch_id,
            'room_id' => $request->integer('room_id'),
            'type' => $request->string('type')->value(),
            'priority' => $request->string('priority')->value(),
            'description' => $request->string('description')->value() ?: null,
            'assigned_to' => $request->filled('assigned_to') ? $request->integer('assigned_to') : null,
            'estimated_minutes' => $request->filled('estimated_minutes') ? $request->integer('estimated_minutes') : null,
            'status' => 'pending',
        ]);

        return $this->flashSuccess('Task created for room '.$task->room?->number.'.');
    }

    public function update(Request $request, Task $task): RedirectResponse
    {
        $this->ensureBranchAccess($task->branch);
        $this->ensureOwnTaskAccess($task);

        $request->validate([
            'assigned_to' => 'nullable|exists:users,id',
            'priority' => 'sometimes|string|in:low,normal,high,urgent',
            'notes' => 'nullable|string',
        ]);

        $data = [];
        if ($request->has('assigned_to')) {
            $data['assigned_to'] = $request->integer('assigned_to') ?: null;
        }
        if ($request->has('priority')) {
            $data['priority'] = $request->string('priority')->value();
        }
        if ($request->has('notes')) {
            $data['notes'] = $request->string('notes')->value() ?: null;
        }

        $task->update($data);

        return $this->flashSuccess('Task updated.');
    }

    public function start(Task $task): RedirectResponse
    {
        $this->ensureBranchAccess($task->branch);
        $this->ensureOwnTaskAccess($task);

        if ($task->status !== 'pending') {
            return back()->withErrors(['status' => 'Task cannot be started.']);
        }

        $task->start();

        return $this->flashSuccess('Task started.');
    }

    public function complete(Request $request, Task $task): RedirectResponse
    {
        $this->ensureBranchAccess($task->branch);
        $this->ensureOwnTaskAccess($task);

        if ($task->status !== 'in_progress') {
            return back()->withErrors(['status' => 'Task is not in progress.']);
        }

        $request->validate([
            'notes' => 'nullable|string',
        ]);

        $notes = $request->string('notes')->value();

        $task->complete($notes !== '' ? $notes : null);

        if ($task->type === 'turnover' || $task->type === 'cleaning') {
            $task->room?->update(['status' => 'available']);
        }

        return $this->flashSuccess('Task completed.');
    }

    public function destroy(Task $task): RedirectResponse
    {
        $this->ensureBranchAccess($task->branch);

        $task->delete();

        return $this->flashSuccess('Task deleted.');
    }

    private function ensureOwnTaskAccess(Task $task): void
    {
        $user = request()->user();

        if ($user && $user->hasRole('Housekeeper') && $task->assigned_to !== $user->id) {
            abort(403, 'You can only update tasks assigned to you.');
        }
    }

    public function mobile(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $userId = $user->id;

        $tasks = Task::where('assigned_to', $userId)
            ->whereIn('status', ['pending', 'in_progress'])
            ->with('room')
            ->orderBy('priority', 'desc')
            ->get();

        return Inertia::render('housekeeping/Mobile', [
            'tasks' => $tasks,
        ]);
    }
}
