<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Branch;
use App\Models\JournalEntry;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class JournalController extends Controller
{
    use EnsuresBranchAccess;

    public function index(Request $request, Branch $branch): Response
    {
        $this->ensureBranchAccess($branch);

        $request->validate([
            'business_date' => 'nullable|date',
            'event' => 'nullable|string|max:64',
        ]);

        $entries = JournalEntry::forBranch($branch->id)
            ->when($request->string('business_date')->value(), fn ($q, $date) => $q->forBusinessDate($date))
            ->when($request->string('event')->value(), fn ($q, $event) => $q->where('event', $event))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('finance/Journal', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'entries' => $entries,
            'filters' => $request->only(['business_date', 'event']),
        ]);
    }
}
