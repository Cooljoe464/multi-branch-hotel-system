<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SetBranchContext
{
    private const SESSION_KEY = 'branch_id';

    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return $next($request);
        }

        $user = $request->user();

        $branch = $this->resolveBranch($request, $user);

        if ($branch) {
            $this->setBranch($request, $branch);
        }

        return $next($request);
    }

    private function resolveBranch(Request $request, $user): ?Branch
    {
        $requestedBranchId = $request->input('branch_id') ?? $request->route('branch');

        if ($requestedBranchId && $this->isValidBranchForUser($user, (int) $requestedBranchId)) {
            return Branch::findOrFail($requestedBranchId);
        }

        $sessionBranchId = session(self::SESSION_KEY);

        if ($sessionBranchId && $this->isValidBranchForUser($user, $sessionBranchId)) {
            return Branch::find($sessionBranchId);
        }

        return $this->getDefaultBranch($user);
    }

    private function getDefaultBranch($user): ?Branch
    {
        $defaultBranch = $user->defaultBranch();

        if ($defaultBranch) {
            return $defaultBranch;
        }

        return $user->branches()->active()->first();
    }

    private function isValidBranchForUser($user, int $branchId): bool
    {
        if ($user->is_global_admin) {
            return Branch::where('id', $branchId)->where('is_active', true)->exists();
        }

        return $user->branches()
            ->where('branches.id', $branchId)
            ->where('branches.is_active', true)
            ->exists();
    }

    private function setBranch(Request $request, Branch $branch): void
    {
        session([self::SESSION_KEY => $branch->id]);

        $request->merge(['_branch' => $branch]);
    }
}
