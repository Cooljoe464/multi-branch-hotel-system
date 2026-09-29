<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Branch;
use App\Models\HkSchedule;
use App\Models\Room;
use App\Models\User;
use App\Services\HkSchedulerService;
use App\Support\BranchTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HkScheduleController extends Controller
{
    use EnsuresBranchAccess;

    public function index(Branch $branch, Request $request): Response
    {
        $this->ensureBranchAccess($branch);

        $request->validate(['date' => 'nullable|date_format:Y-m-d']);

        $dateInput = $request->string('date')->value();
        $date = $dateInput !== '' ? $dateInput : BranchTime::today($branch);

        $schedule = HkSchedule::forBranch($branch->id)->where('work_date', $date)->first();

        $rooms = Room::forBranch($branch->id)->where('is_active', true)->orderBy('number')->get(['id', 'number', 'floor', 'status']);
        $attendants = User::where('branch_id', $branch->id)
            ->whereHas('roles', fn ($q) => $q->where('name', 'Housekeeper'))
            ->orderBy('id')
            ->get(['id', 'name']);

        return Inertia::render('housekeeping/Schedule', [
            'branch' => $branch->only(['id', 'name']),
            'date' => $date,
            'schedule' => $schedule ? [
                'id' => $schedule->id,
                'status' => $schedule->status,
                'assignments' => $schedule->assignments ?? [],
            ] : null,
            'rooms' => $rooms,
            'attendants' => $attendants,
        ]);
    }

    public function plan(Branch $branch, Request $request, HkSchedulerService $scheduler): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $request->validate(['date' => 'required|date_format:Y-m-d']);

        $scheduler->plan($branch, $request->string('date')->value());

        return redirect()->route('hk-schedules.index', ['branch' => $branch->id, 'date' => $request->string('date')->value()])
            ->with('toast', ['type' => 'success', 'message' => 'Schedule drafted. Pins were preserved.']);
    }

    public function pin(Branch $branch, HkSchedule $schedule, Request $request, HkSchedulerService $scheduler): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($schedule->branch_id === $branch->id && $schedule->status === HkSchedule::STATUS_DRAFT, 404);

        $request->validate([
            'room_id' => 'required|integer',
            'attendant_id' => 'nullable|integer',
            'pinned' => 'required|boolean',
        ]);

        $assignments = $scheduler->normalizeAssignments($schedule->assignments);
        $roomId = $request->integer('room_id');

        if (! isset($assignments[$roomId])) {
            return back()->withErrors(['room_id' => 'Room is not on this schedule.']);
        }

        $assignments[$roomId]['pinned'] = $request->boolean('pinned');

        if ($request->filled('attendant_id')) {
            $assignments[$roomId]['attendant_id'] = $request->integer('attendant_id');
        }

        $schedule->update(['assignments' => $assignments]);

        return redirect()->route('hk-schedules.index', ['branch' => $branch->id, 'date' => $schedule->work_date->toDateString()])
            ->with('toast', ['type' => 'success', 'message' => 'Pin updated.']);
    }

    public function publish(Branch $branch, HkSchedule $schedule, Request $request, HkSchedulerService $scheduler): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($schedule->branch_id === $branch->id, 404);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $scheduler->publish($schedule, $user);

        return redirect()->route('hk-schedules.index', ['branch' => $branch->id, 'date' => $schedule->work_date->toDateString()])
            ->with('toast', ['type' => 'success', 'message' => 'Schedule published to the floor.']);
    }
}
