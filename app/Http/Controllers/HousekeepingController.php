<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HousekeepingController extends Controller
{
    public function index(Request $request): Response
    {
        $branchId = $request->user()->branch_id;

        $tasks = Task::forBranch($branchId)
            ->with(['room', 'assignee'])
            ->when($request->status, fn ($q, $status) => $q->forStatus($status))
            ->when($request->type, fn ($q, $type) => $q->forType($type))
            ->when($request->assigned_to, fn ($q, $userId) => $q->forUser($userId))
            ->orderBy('priority', 'desc')
            ->orderBy('created_at', 'asc')
            ->get();

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

    public function store(Request $request)
    {
        $validated = $request->validate([
            'room_id' => 'required|exists:rooms,id',
            'type' => 'required|string|in:cleaning,deep_clean,turnover,inspection,laundry,maintenance_request',
            'priority' => 'required|string|in:low,normal,high,urgent',
            'description' => 'nullable|string',
            'assigned_to' => 'nullable|exists:users,id',
            'estimated_minutes' => 'nullable|integer|min:5|max:480',
        ]);

        $validated['branch_id'] = $request->user()->branch_id;
        $validated['status'] = 'pending';

        $task = Task::create($validated);

        return back()->with('success', 'Task created for room '.$task->room?->number.'.');
    }

    public function update(Request $request, Task $task)
    {
        $validated = $request->validate([
            'assigned_to' => 'nullable|exists:users,id',
            'priority' => 'sometimes|string|in:low,normal,high,urgent',
            'notes' => 'nullable|string',
        ]);

        $task->update($validated);

        return back()->with('success', 'Task updated.');
    }

    public function start(Task $task)
    {
        if ($task->status !== 'pending') {
            return back()->withErrors(['status' => 'Task cannot be started.']);
        }

        $task->start();

        return back()->with('success', 'Task started.');
    }

    public function complete(Request $request, Task $task)
    {
        if ($task->status !== 'in_progress') {
            return back()->withErrors(['status' => 'Task is not in progress.']);
        }

        $validated = $request->validate([
            'notes' => 'nullable|string',
        ]);

        $task->complete($validated['notes'] ?? null);

        if ($task->type === 'turnover' || $task->type === 'cleaning') {
            $task->room?->update(['status' => 'available']);
        }

        return back()->with('success', 'Task completed.');
    }

    public function destroy(Task $task)
    {
        $task->delete();

        return back()->with('success', 'Task deleted.');
    }

    public function mobile(Request $request): Response
    {
        $userId = $request->user()->id;

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
