<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessExcelImportJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ImportController extends Controller
{
    public function roomsForm(): Response
    {
        $user = Auth::user();
        abort_unless($user !== null, 401);

        return Inertia::render('admin/import/Rooms', [
            'branch' => $user->currentBranch,
        ]);
    }

    public function roomsImport(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,xlsx,xls|max:10240',
        ]);

        $file = $request->file('file');

        if (! $file) {
            return back()->withErrors(['file' => 'File upload failed.']);
        }

        $path = $file->store('imports', 'local');

        if ($path === false) {
            return back()->withErrors(['file' => 'Failed to store uploaded file.']);
        }

        $user = Auth::user();

        if (! $user) {
            abort(401);
        }

        $branch = $user->currentBranch;
        abort_unless($branch !== null, 403, 'No branch context set.');

        ProcessExcelImportJob::dispatch($path, 'rooms', $branch->id, $user->id);

        return back()->with([
            'flash' => [
                'type' => 'success',
                'message' => 'Room import queued for processing.',
            ],
        ]);
    }

    public function guestsForm(): Response
    {
        $user = Auth::user();
        abort_unless($user !== null, 401);

        return Inertia::render('admin/import/Guests', [
            'branch' => $user->currentBranch,
        ]);
    }

    public function guestsImport(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,xlsx,xls|max:10240',
        ]);

        $file = $request->file('file');

        if (! $file) {
            return back()->withErrors(['file' => 'File upload failed.']);
        }

        $path = $file->store('imports', 'local');

        if ($path === false) {
            return back()->withErrors(['file' => 'Failed to store uploaded file.']);
        }

        $user = Auth::user();

        if (! $user) {
            abort(401);
        }

        $branch = $user->currentBranch;
        abort_unless($branch !== null, 403, 'No branch context set.');

        ProcessExcelImportJob::dispatch($path, 'guests', $branch->id, $user->id);

        return back()->with([
            'flash' => [
                'type' => 'success',
                'message' => 'Guest import queued for processing.',
            ],
        ]);
    }

    public function reservationsForm(): Response
    {
        $user = Auth::user();
        abort_unless($user !== null, 401);

        return Inertia::render('admin/import/Reservations', [
            'branch' => $user->currentBranch,
        ]);
    }

    public function reservationsImport(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,xlsx,xls|max:10240',
        ]);

        $file = $request->file('file');

        if (! $file) {
            return back()->withErrors(['file' => 'File upload failed.']);
        }

        $path = $file->store('imports', 'local');

        if ($path === false) {
            return back()->withErrors(['file' => 'Failed to store uploaded file.']);
        }

        $user = Auth::user();

        if (! $user) {
            abort(401);
        }

        $branch = $user->currentBranch;
        abort_unless($branch !== null, 403, 'No branch context set.');

        ProcessExcelImportJob::dispatch($path, 'reservations', $branch->id, $user->id);

        return back()->with([
            'flash' => [
                'type' => 'success',
                'message' => 'Reservation import queued for processing.',
            ],
        ]);
    }
}
