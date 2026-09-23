<?php

namespace App\Http\Controllers;

use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Models\DsarRequest;
use App\Models\Guest;
use App\Models\RetentionPolicy;
use App\Services\DsarService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DsarController extends Controller
{
    use Concerns\EnsuresBranchAccess;

    public function index(Request $request, Branch $branch): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $this->ensureBranchAccess($branch);

        return Inertia::render('compliance/Dsar', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'requests' => DsarRequest::where('branch_id', $branch->id)
                ->with('guest')
                ->orderByDesc('id')
                ->paginate(25)
                ->withQueryString(),
            'policies' => RetentionPolicy::orderBy('data_class')->get(),
        ]);
    }

    public function store(Request $request, Branch $branch): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $this->ensureBranchAccess($branch);

        $request->validate([
            'guest_id' => 'required|exists:guests,id',
            'kind' => 'required|string|in:access,erasure,portability',
        ]);

        $guest = Guest::findOrFail($request->integer('guest_id'));

        try {
            (new DsarService)->intake($branch, $guest, $request->string('kind')->value(), $user);
        } catch (AvailabilityException $e) {
            return back()->withErrors(['dsar' => $e->getMessage()]);
        }

        return $this->flashSuccess('DSAR intake recorded; the regulatory clock is running.');
    }

    public function fulfill(Request $request, Branch $branch, DsarRequest $dsar): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $this->ensureBranchAccess($branch);
        abort_unless($dsar->branch_id === null || $dsar->branch_id === $branch->id, 404);

        try {
            (new DsarService)->fulfill($dsar, $user);
        } catch (AvailabilityException $e) {
            return back()->withErrors(['dsar' => $e->getMessage()]);
        }

        return $this->flashSuccess('DSAR fulfilled (bundles stored, erasures queued for purge).');
    }

    public function reject(Request $request, Branch $branch, DsarRequest $dsar): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $this->ensureBranchAccess($branch);
        abort_unless($dsar->branch_id === null || $dsar->branch_id === $branch->id, 404);

        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        (new DsarService)->reject($dsar, $user, $request->string('reason')->value());

        return $this->flashSuccess('DSAR rejected with a recorded reason.');
    }

    public function download(Request $request, Branch $branch, DsarRequest $dsar): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $this->ensureBranchAccess($branch);
        abort_unless($dsar->branch_id === null || $dsar->branch_id === $branch->id, 404);

        try {
            $url = (new DsarService)->downloadUrl($dsar, $user);
        } catch (AvailabilityException $e) {
            return back()->withErrors(['dsar' => $e->getMessage()]);
        }

        return redirect()->away($url);
    }
}
