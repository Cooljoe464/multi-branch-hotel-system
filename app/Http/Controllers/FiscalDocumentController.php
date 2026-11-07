<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Branch;
use App\Models\FiscalDocument;
use App\Services\Fiscal\FiscalService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class FiscalDocumentController extends Controller
{
    use EnsuresBranchAccess;

    public function index(Branch $branch): Response
    {
        $this->ensureBranchAccess($branch);

        return Inertia::render('finance/FiscalDocuments', [
            'branch' => $branch->only(['id', 'name']),
            'heldDocuments' => FiscalDocument::query()
                ->where('branch_id', $branch->id)
                ->where('document_type', 'invoice')
                ->where('status', FiscalDocument::STATUS_DRAFT)
                ->with('folio:id,folio_number')
                ->latest('id')
                ->get(['id', 'branch_id', 'folio_id', 'status', 'last_error', 'created_at']),
        ]);
    }

    public function release(Branch $branch, FiscalDocument $document, FiscalService $fiscal): RedirectResponse
    {
        $this->ensureBranchAccess($branch);
        abort_unless($document->branch_id === $branch->id, 404);

        try {
            $fiscal->releaseHeldDocument($document);
        } catch (DomainException $exception) {
            return back()->withErrors(['fiscal_document' => $exception->getMessage()]);
        }

        return back()->with('success', 'Fiscal invoice released for provider submission.');
    }
}
