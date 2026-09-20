<?php

namespace App\Http\Controllers;

use App\Models\ChannelProviderModel;
use App\Services\ChannelSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChannelManagerController extends Controller
{
    use Concerns\EnsuresBranchAccess;

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $branchId = (int) $user->branch_id;

        $providers = ChannelProviderModel::where('branch_id', $branchId)
            ->withCount('channelReservations')
            ->withCount('channelRates')
            ->get();

        return Inertia::render('channels/Index', [
            'channelProviders' => $providers,
        ]);
    }

    public function sync(Request $request, ChannelProviderModel $channelProvider): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        if ($channelProvider->branch_id !== $user->branch_id) {
            abort(403, 'Provider does not belong to this branch.');
        }

        $syncService = new ChannelSyncService;
        $result = $syncService->syncRates($channelProvider);
        $synced = (int) $result['synced'];
        $errorCount = count($result['errors']);

        if ($errorCount > 0) {
            return back()->with('warning', "Synced {$synced} rates. {$errorCount} errors occurred.");
        }

        return $this->flashSuccess("Synced {$synced} rates for {$channelProvider->provider}.");
    }

    public function pullReservations(Request $request, ChannelProviderModel $channelProvider): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        if ($channelProvider->branch_id !== $user->branch_id) {
            abort(403, 'Provider does not belong to this branch.');
        }

        $syncService = new ChannelSyncService;
        $result = $syncService->pullReservations($channelProvider);
        $pulled = (int) $result['pulled'];
        $errorCount = count($result['errors']);

        if ($errorCount > 0) {
            return back()->with('warning', "Pulled {$pulled} reservations. {$errorCount} errors occurred.");
        }

        return $this->flashSuccess("Pulled {$pulled} reservations from {$channelProvider->provider}.");
    }
}
