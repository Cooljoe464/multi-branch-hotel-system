<?php

namespace App\Http\Controllers;

use App\Exceptions\AvailabilityException;
use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Branch;
use App\Models\HousekeepingTask;
use App\Models\Room;
use App\Models\RoomOut;
use App\Models\User;
use App\Services\HousekeepingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HousekeepingTaskController extends Controller
{
    use EnsuresBranchAccess;

    public function index(Branch $branch): Response
    {
        $this->ensureBranchAccess($branch);

        $tasks = HousekeepingTask::forBranch($branch->id)
            ->with(['room', 'assignee'])
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 WHEN status = 'in_progress' THEN 1 ELSE 2 END")
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        $load = HousekeepingTask::forBranch($branch->id)
            ->open()
            ->whereNotNull('assignee_id')
            ->selectRaw('assignee_id, SUM(credits) as credits, COUNT(*) as tasks')
            ->groupBy('assignee_id')
            ->with('assignee')
            ->get();

        $rooms = Room::forBranch($branch->id)
            ->where('is_active', true)
            ->orderBy('number')
            ->get(['id', 'number', 'status', 'condition', 'condition_reason']);

        return Inertia::render('housekeeping/Board', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'tasks' => $tasks,
            'load' => $load,
            'rooms' => $rooms,
        ]);
    }

    public function store(Request $request, Branch $branch): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $request->validate([
            'room_id' => 'required|exists:rooms,id',
            'kind' => 'required|string|in:checkout_clean,stayover,turndown,inspection,minibar_check',
            'credits' => 'nullable|integer|min:1|max:100',
        ]);

        $room = Room::findOrFail($request->integer('room_id'));
        abort_unless($room->branch_id === $branch->id, 403);

        HousekeepingTask::create([
            'branch_id' => $branch->id,
            'room_id' => $room->id,
            'kind' => $request->string('kind')->value(),
            'credits' => $request->integer('credits', 10),
            'status' => HousekeepingTask::STATUS_OPEN,
        ]);

        return $this->flashSuccess('Task created.');
    }

    public function assign(Request $request, Branch $branch, HousekeepingTask $task): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($task->branch_id === $branch->id, 404);

        $request->validate([
            'assignee_id' => 'required|exists:users,id',
        ]);

        $assignee = User::findOrFail($request->integer('assignee_id'));

        try {
            (new HousekeepingService)->assign($task, $assignee, $request->user());
        } catch (AvailabilityException $e) {
            return back()->withErrors(['assignee_id' => $e->getMessage()]);
        }

        return $this->flashSuccess('Task assigned.');
    }

    public function autoAllocate(Branch $branch): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $result = (new HousekeepingService)->autoAllocate($branch);

        return $this->flashSuccess("Allocated {$result['assigned']} tasks ({$result['skipped']} skipped over cap).");
    }

    public function complete(Request $request, Branch $branch, HousekeepingTask $task): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($task->branch_id === $branch->id, 404);

        $user = $request->user();
        abort_unless($user !== null, 401);

        if (! $user->can('housekeeping.assign') && $task->assignee_id !== $user->id) {
            abort(403, 'You can only complete your own tasks.');
        }

        $request->validate([
            'inspection_score' => 'nullable|integer|min:0|max:100',
            'photos' => 'nullable|array|max:5',
            'photos.*' => 'image|max:10240',
        ]);

        $uploaded = $request->file('photos');
        $photos = [];
        if (is_array($uploaded)) {
            foreach ($uploaded as $file) {
                $photos[] = $file;
            }
        }

        $rawScore = $request->input('inspection_score');
        $score = is_int($rawScore) ? $rawScore : null;

        try {
            (new HousekeepingService)->complete($task, $user, $score, $photos);
        } catch (AvailabilityException $e) {
            return back()->withErrors(['task' => $e->getMessage()]);
        }

        return $this->flashSuccess('Task completed.');
    }

    public function setCondition(Request $request, Branch $branch, Room $room): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($room->branch_id === $branch->id, 404);

        $request->validate([
            'condition' => 'required|string|in:clean,dirty,inspected,ooo,oos',
            'reason' => 'nullable|string|max:255',
        ]);

        try {
            (new HousekeepingService)->setCondition(
                $room,
                $request->string('condition')->value(),
                $request->string('reason')->value() ?: null,
                $request->user(),
            );
        } catch (AvailabilityException $e) {
            return back()->withErrors(['condition' => $e->getMessage()]);
        }

        return $this->flashSuccess('Room condition updated.');
    }

    public function storeOutOfOrder(Request $request, Branch $branch, Room $room): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($room->branch_id === $branch->id, 404);

        $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
            'reason' => 'nullable|string|max:255',
        ]);

        try {
            (new HousekeepingService)->setOutOfOrder(
                $room,
                $request->string('from_date')->value(),
                $request->string('to_date')->value(),
                $request->string('reason')->value() ?: null,
                $request->user(),
            );
        } catch (AvailabilityException $e) {
            return back()->withErrors(['from_date' => $e->getMessage()]);
        }

        return $this->flashSuccess('Room taken out of order; inventory adjusted.');
    }

    public function clearOutOfOrder(Branch $branch, RoomOut $out): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($out->branch_id === $branch->id, 404);

        (new HousekeepingService)->clearOutOfOrder($out, request()->user());

        return $this->flashSuccess('Room back in service; inventory restored.');
    }
}
