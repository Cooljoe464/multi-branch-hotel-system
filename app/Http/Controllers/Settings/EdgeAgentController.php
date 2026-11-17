<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\EdgeAgent;
use App\Services\EdgeAgentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Edge agents are on-premises nodes that keep a property running offline.
 * A token is shown once at issue time; only its hash is stored.
 */
class EdgeAgentController extends Controller
{
    private const STALE_AFTER_MINUTES = 5;

    public function index(Request $request): Response
    {
        $branch = $this->branch($request);

        $agents = EdgeAgent::query()
            ->where('branch_id', $branch->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (EdgeAgent $agent): array => [
                'id' => $agent->id,
                'name' => $agent->name,
                'agent_id' => $agent->agent_id,
                'status' => $agent->status,
                'protocol_version' => $agent->protocol_version,
                'last_seen_at' => $agent->last_seen_at?->toIso8601String(),
                'is_stale' => $agent->status === EdgeAgent::STATUS_ACTIVE
                    && ($agent->last_seen_at === null || $agent->last_seen_at->lt(now()->subMinutes(self::STALE_AFTER_MINUTES))),
            ])
            ->values();

        return Inertia::render('settings/EdgeAgents', [
            'branch' => $branch->only(['id', 'name']),
            'agents' => $agents,
            'current_version' => config()->string('edge.current_version'),
        ]);
    }

    public function store(Request $request, EdgeAgentService $service): RedirectResponse
    {
        $branch = $this->branch($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
        ]);

        $issued = $service->issue($branch, $data['name'], config()->string('edge.current_version'));

        return back()
            ->with('token', $issued['token'])
            ->with('toast', ['type' => 'success', 'message' => "Edge agent {$issued['agent']->name} issued. Copy the token now — it is shown once."]);
    }

    public function revoke(Request $request, EdgeAgent $agent): RedirectResponse
    {
        abort_unless($agent->branch_id === $this->branch($request)->id, 403);

        $agent->update(['status' => EdgeAgent::STATUS_REVOKED]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Edge agent revoked. Its token no longer works.']);
    }

    private function branch(Request $request): Branch
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $branch = $user->currentBranch;
        abort_unless($branch instanceof Branch, 404);

        return $branch;
    }
}
