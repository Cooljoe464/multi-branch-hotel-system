<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Branch;

trait EnsuresBranchAccess
{
    protected function ensureBranchAccess(?Branch $branch): void
    {
        if (! $branch) {
            return;
        }

        $user = request()->user();

        if (! $user || ! $user->hasAccessToBranch($branch)) {
            abort(403, 'You do not have access to this property.');
        }
    }

    protected function ensureSameBranchAccess(int $resourceBranchId, int $userBranchId): void
    {
        $user = request()->user();

        if (! $user || $user->is_global_admin) {
            return;
        }

        if ($resourceBranchId !== $userBranchId) {
            $branch = Branch::find($resourceBranchId);

            if (! $branch || ! $user->hasAccessToBranch($branch)) {
                abort(403, 'You do not have access to this property.');
            }
        }
    }
}
