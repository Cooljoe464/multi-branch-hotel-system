<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Asset;
use App\Models\AssetHealthScore;
use App\Models\Branch;
use App\Models\MaintenanceTicket;
use App\Services\MaintenanceService;
use App\Services\PredictiveMaintenanceService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AssetHealthController extends Controller
{
    use EnsuresBranchAccess;

    public function index(Branch $branch): Response
    {
        $this->ensureBranchAccess($branch);

        $assets = Asset::forBranch($branch->id)->orderBy('name')->with('room')->get()->map(function (Asset $asset) {
            $score = AssetHealthScore::where('asset_id', $asset->id)->latest('scored_on')->first();
            $draft = MaintenanceTicket::where('asset_id', $asset->id)
                ->whereIn('status', ['draft', 'open', 'in_progress'])
                ->whereJsonContains('metadata->pm_draft', true)
                ->latest('id')
                ->first(['id', 'status']);

            return [
                'id' => $asset->id,
                'name' => $asset->name,
                'category' => $asset->category,
                'room' => $asset->room?->number,
                'failure_prob' => $score ? $score->failure_prob : null,
                'scored_on' => $score ? $score->scored_on->toDateString() : null,
                'signals' => $score ? ($score->signals ?? []) : [],
                'draft_ticket_id' => $draft ? $draft->id : null,
                'draft_status' => $draft ? $draft->status : null,
            ];
        })->sortByDesc('failure_prob')->values()->all();

        return Inertia::render('maintenance/Health', [
            'branch' => $branch->only(['id', 'name']),
            'assets' => $assets,
        ]);
    }

    public function rescore(Branch $branch, PredictiveMaintenanceService $service): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $result = $service->scoreBranch($branch);

        return redirect()->route('asset-health.index', $branch)
            ->with('toast', ['type' => 'success', 'message' => "Scored {$result['scored']} assets, {$result['drafts']} new drafts."]);
    }

    public function publish(Branch $branch, MaintenanceTicket $ticket, MaintenanceService $maintenance): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($ticket->branch_id === $branch->id, 404);

        $maintenance->publish($ticket);

        return redirect()->route('asset-health.index', $branch)
            ->with('toast', ['type' => 'success', 'message' => "Draft #{$ticket->id} published to the queue."]);
    }

    public function destroy(Branch $branch, MaintenanceTicket $ticket): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($ticket->branch_id === $branch->id && $ticket->status === 'draft', 404);

        $ticket->delete();

        return redirect()->route('asset-health.index', $branch)
            ->with('toast', ['type' => 'success', 'message' => 'Draft discarded.']);
    }
}
