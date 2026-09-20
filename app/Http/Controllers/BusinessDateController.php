<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Branch;
use App\Services\BusinessDateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BusinessDateController extends Controller
{
    use EnsuresBranchAccess;

    public function show(Branch $branch, BusinessDateService $businessDates): Response
    {
        $this->ensureBranchAccess($branch);

        $current = $businessDates->current($branch);

        $history = $branch->businessDates()
            ->orderByDesc('business_date')
            ->limit(14)
            ->get();

        return Inertia::render('business-date/Show', [
            'branch' => $branch->only(['id', 'name', 'code', 'timezone']),
            'current' => $current,
            'history' => $history,
        ]);
    }

    public function advance(Request $request, Branch $branch, BusinessDateService $businessDates): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $businessDates->advance($branch, $request->user());

        return $this->flashSuccess('Business date advanced.');
    }
}
