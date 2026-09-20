<?php

namespace App\Http\Controllers;

use App\Models\AuditFlag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditFlagController extends Controller
{
    use Concerns\EnsuresBranchAccess;

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $branchId = (int) $user->branch_id;

        $flags = AuditFlag::where('branch_id', $branchId)
            ->with(['reviewer'])
            ->when($request->filled('severity'), fn ($q) => $q->where('severity', $request->string('severity')->value()))
            ->when($request->filled('reviewed'), function ($q) use ($request) {
                $q->where('is_reviewed', $request->boolean('reviewed'));
            })
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('audit/Flags', [
            'auditFlags' => $flags,
            'filters' => $request->only(['severity', 'reviewed']),
        ]);
    }

    public function show(AuditFlag $auditFlag): Response
    {
        $this->ensureBranchAccess($auditFlag->branch);

        return Inertia::render('audit/Show', [
            'auditFlag' => $auditFlag->load(['reviewer']),
        ]);
    }

    public function review(Request $request, AuditFlag $auditFlag): RedirectResponse
    {
        $this->ensureBranchAccess($auditFlag->branch);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $auditFlag->update([
            'is_reviewed' => true,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        return $this->flashSuccess('Audit flag reviewed.');
    }

    public function suppress(AuditFlag $auditFlag): RedirectResponse
    {
        $this->ensureBranchAccess($auditFlag->branch);

        $user = request()->user();
        abort_unless($user !== null, 401);

        $auditFlag->update([
            'is_reviewed' => true,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'metadata' => array_merge($auditFlag->metadata ?? [], ['suppressed' => true]),
        ]);

        return $this->flashSuccess('Audit flag suppressed.');
    }
}
