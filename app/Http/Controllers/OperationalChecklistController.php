<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Branch;
use App\Models\OperationalChecklist;
use App\Services\ChecklistService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OperationalChecklistController extends Controller
{
    use EnsuresBranchAccess;

    public function __construct(private ChecklistService $checklistService) {}

    public function index(Request $request, Branch $branch): Response
    {
        $this->ensureBranchAccess($branch);

        $checklists = OperationalChecklist::forBranch($branch->id)
            ->with(['completedByUser', 'verifiedByUser'])
            ->when($request->filled('department'), fn ($q) => $q->where('department', $request->string('department')->value()))
            ->when($request->filled('shift_type'), fn ($q) => $q->where('shift_type', $request->string('shift_type')->value()))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->value()))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('operations/Checklists', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'checklists' => $checklists,
            'filters' => $request->only(['department', 'shift_type', 'status']),
        ]);
    }

    public function store(Request $request, Branch $branch): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $request->validate([
            'department' => 'required|in:front_desk,housekeeping,food_beverage,security,maintenance',
            'shift_type' => 'required|in:morning,evening,night',
            'title' => 'required|string|max:255',
            'tasks' => 'required|array|min:1',
            'tasks.*' => 'required|string|max:255',
        ]);

        $this->checklistService->createFromTemplate(
            $branch,
            $request->string('department')->value(),
            $request->string('shift_type')->value(),
            $request->string('title')->value(),
            $request->input('tasks'),
        );

        return $this->flashSuccess('Checklist created.');
    }

    public function checkItem(Request $request, Branch $branch, OperationalChecklist $checklist, int $itemId): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($checklist->branch_id === $branch->id, 404);

        $request->validate([
            'note' => 'nullable|string|max:500',
        ]);

        $this->checklistService->checkItem(
            $checklist,
            $itemId,
            $request->filled('note') ? $request->string('note')->value() : null,
        );

        return $this->flashSuccess('Task checked.');
    }

    public function complete(Request $request, Branch $branch, OperationalChecklist $checklist): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($checklist->branch_id === $branch->id, 404);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $this->checklistService->complete($checklist, $user);

        return $this->flashSuccess('Checklist completed.');
    }

    public function verify(Request $request, Branch $branch, OperationalChecklist $checklist): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($checklist->branch_id === $branch->id, 404);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $this->checklistService->verify($checklist, $user);

        return $this->flashSuccess('Checklist verified.');
    }
}
