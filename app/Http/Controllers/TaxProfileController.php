<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Branch;
use App\Models\TaxComponent;
use App\Models\TaxProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TaxProfileController extends Controller
{
    use EnsuresBranchAccess;

    public function index(Branch $branch): Response
    {
        $this->ensureBranchAccess($branch);

        $profiles = TaxProfile::forBranch($branch->id)->with('components')->get();

        return Inertia::render('settings/TaxProfiles', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'profiles' => $profiles,
        ]);
    }

    public function store(Request $request, Branch $branch): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $request->validate([
            'jurisdiction' => 'required|string|max:32',
            'name' => 'required|string|max:64',
            'components' => 'required|array|min:1',
            'components.*.code' => 'required|string|max:32',
            'components.*.mode' => 'required|in:exclusive,inclusive',
            'components.*.rate_bps' => 'required|integer|min:0|max:100000',
            'components.*.applies_to' => 'nullable|string|max:32',
        ]);

        $profile = TaxProfile::create([
            'branch_id' => $branch->id,
            'jurisdiction' => $request->string('jurisdiction')->value(),
            'name' => $request->string('name')->value(),
            'active' => true,
        ]);

        $components = $request->input('components');

        if (! is_array($components)) {
            abort(422, 'Components must be an array.');
        }

        foreach (array_values($components) as $sequence => $component) {
            if (! is_array($component)) {
                continue;
            }

            $code = $component['code'] ?? null;
            $mode = $component['mode'] ?? null;
            $rateBps = $component['rate_bps'] ?? null;
            $appliesTo = $component['applies_to'] ?? 'all';

            if (! is_string($code) || ! is_string($mode) || ! is_int($rateBps) || ! is_string($appliesTo)) {
                continue;
            }

            TaxComponent::create([
                'tax_profile_id' => $profile->id,
                'code' => $code,
                'mode' => $mode,
                'rate_bps' => $rateBps,
                'applies_to' => $appliesTo,
                'sequence' => $sequence,
            ]);
        }

        return $this->flashSuccess('Tax profile created.');
    }

    public function deactivate(Branch $branch, TaxProfile $profile): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        abort_unless($profile->branch_id === $branch->id, 404);

        $profile->update(['active' => false]);

        return $this->flashSuccess('Tax profile deactivated. Posted lines keep their frozen snapshots.');
    }
}
