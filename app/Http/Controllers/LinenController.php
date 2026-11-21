<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Branch;
use App\Models\LaundryBatch;
use App\Models\LinenItem;
use App\Services\LinenTrackingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LinenController extends Controller
{
    use EnsuresBranchAccess;

    public function __construct(private LinenTrackingService $linenService) {}

    public function index(Request $request, Branch $branch): Response
    {
        $this->ensureBranchAccess($branch);

        $items = LinenItem::forBranch($branch->id)->get();
        $summary = $this->linenService->inventorySummary($branch);
        $alerts = $this->linenService->parLevelAlerts($branch);

        $batches = LaundryBatch::forBranch($branch->id)
            ->with(['dispatcher', 'receiver'])
            ->orderByDesc('dispatched_at')
            ->limit(20)
            ->get();

        return Inertia::render('housekeeping/LinenManagement', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'items' => $items,
            'summary' => $summary,
            'alerts' => $alerts,
            'batches' => $batches,
        ]);
    }

    public function dispatch(Request $request, Branch $branch): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $request->validate([
            'item_counts' => 'required|array|min:1',
            'item_counts.*' => 'required|integer|min:1',
            'vendor_type' => 'required|in:internal,outsourced',
            'vendor_name' => 'nullable|string|max:255',
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $batch = $this->linenService->dispatchBatch(
            $branch,
            $user,
            $request->input('item_counts'),
            $request->string('vendor_type')->value(),
            $request->filled('vendor_name') ? $request->string('vendor_name')->value() : null,
        );

        return $this->flashSuccess("Laundry batch {$batch->batch_number} dispatched.");
    }

    public function receive(Request $request, Branch $branch, LaundryBatch $batch): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($batch->branch_id === $branch->id, 404);

        $request->validate([
            'actual_counts' => 'nullable|array',
            'actual_counts.*' => 'required|integer|min:0',
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $this->linenService->receiveBatch(
            $batch,
            $user,
            $request->input('actual_counts'),
        );

        return $this->flashSuccess("Laundry batch {$batch->batch_number} received.");
    }

    public function discard(Request $request, Branch $branch): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $request->validate([
            'linen_item_id' => 'required|exists:linen_items,id',
            'quantity' => 'required|integer|min:1',
            'reason' => 'required|in:torn,stained,burn,worn_out,missing',
            'from_pool' => 'sometimes|in:dirty,clean',
            'notes' => 'nullable|string|max:500',
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $this->linenService->recordDiscard(
            $branch,
            $request->integer('linen_item_id'),
            $request->integer('quantity'),
            $request->string('reason')->value(),
            $user,
            $request->input('from_pool', 'dirty'),
            $request->filled('notes') ? $request->string('notes')->value() : null,
        );

        return $this->flashSuccess('Linen discard recorded.');
    }
}
