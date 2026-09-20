<?php

namespace App\Http\Controllers;

use App\Models\Folio;
use App\Models\FolioDispute;
use App\Services\FolioService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FolioDisputeController extends Controller
{
    use Concerns\EnsuresBranchAccess;

    public function store(Folio $folio, Request $request): RedirectResponse
    {
        $this->ensureBranchAccess($folio->branch);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $request->validate([
            'transaction_id' => 'nullable|integer|exists:transactions,id',
            'reason' => 'required|string|max:1000',
        ]);

        $folioService = new FolioService;
        $folioService->initiateDispute(
            $folio,
            $request->filled('transaction_id') ? $request->integer('transaction_id') : null,
            $request->string('reason')->value(),
            $user->id,
        );

        return $this->flashSuccess('Dispute initiated successfully.');
    }

    public function update(FolioDispute $dispute, Request $request): RedirectResponse
    {
        $this->ensureBranchAccess($dispute->folio->branch);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $request->validate([
            'status' => 'required|in:under_review,resolved,rejected',
            'resolution_notes' => 'required|string|max:1000',
        ]);

        $folioService = new FolioService;
        $status = $request->string('status')->value();
        $notes = $request->string('resolution_notes')->value();

        if ($status === 'resolved') {
            $folioService->resolveDispute($dispute, $user->id, $notes);
        } elseif ($status === 'rejected') {
            $folioService->rejectDispute($dispute, $user->id, $notes);
        } elseif ($status === 'under_review') {
            $dispute->review();
        }

        return $this->flashSuccess('Dispute updated successfully.');
    }
}
