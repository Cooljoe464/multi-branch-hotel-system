<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Branch;
use App\Models\DoNotRent;
use App\Services\GuestDedupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DoNotRentController extends Controller
{
    use EnsuresBranchAccess;

    public function index(Branch $branch): Response
    {
        $this->ensureBranchAccess($branch);

        return Inertia::render('guests/Dnr', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'entries' => DoNotRent::forBranch($branch->id)
                ->with(['guest', 'branch'])
                ->orderByDesc('id')
                ->paginate(25)
                ->withQueryString(),
        ]);
    }

    public function store(Request $request, Branch $branch): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $request->validate([
            'guest_id' => 'nullable|exists:guests,id',
            'email' => 'nullable|email|max:255',
            'reason' => 'required|string|max:256',
            'scope' => 'nullable|string|in:branch,global',
        ]);

        if (! $request->filled('guest_id') && ! $request->filled('email')) {
            return back()->withErrors(['guest_id' => 'A guest or an email is required.']);
        }

        $global = $request->string('scope', 'branch')->value() === 'global';

        try {
            (new GuestDedupService)->listDnr(
                $global ? null : $branch,
                $request->filled('guest_id') ? $request->integer('guest_id') : null,
                $request->string('email')->value() ?: null,
                $request->string('reason')->value(),
                $user,
            );
        } catch (\Throwable $e) {
            return back()->withErrors(['dnr' => $e->getMessage()]);
        }

        return $this->flashSuccess('Do-not-rent entry listed; bookings now block.');
    }

    public function destroy(Branch $branch, DoNotRent $entry): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($entry->branch_id === null || $entry->branch_id === $branch->id, 404);

        $entry->delete();

        activity('guests')
            ->causedBy(request()->user())
            ->withProperties(['dnr_id' => $entry->id])
            ->log("Do-not-rent entry {$entry->id} removed.");

        return $this->flashSuccess('Do-not-rent entry removed.');
    }
}
