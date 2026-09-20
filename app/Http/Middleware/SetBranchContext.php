<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Models\User;
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
            $response = $next($request);
            if (! $response instanceof Response) {
                abort(500);
            }

            return $response;
        }

        $user = $request->user();

        $branch = $this->resolveBranch($request, $user);

        if ($branch) {
            $this->setBranch($request, $branch);
        }

        $response = $next($request);
        if (! $response instanceof Response) {
            abort(500);
        }

        return $response;
    }

    private function resolveBranch(Request $request, ?User $user): ?Branch
    {
        if (! $user) {
            return null;
        }

        $requestedBranchId = $request->input('branch_id') ?? $request->route('branch');

        if (is_numeric($requestedBranchId)) {
            $branchId = (int) $requestedBranchId;
            if ($this->isValidBranchForUser($user, $branchId)) {
                return Branch::findOrFail($branchId);
            }
        }

        $sessionBranchId = session(self::SESSION_KEY);

        if (is_numeric($sessionBranchId)) {
            $branchId = (int) $sessionBranchId;
            if ($this->isValidBranchForUser($user, $branchId)) {
                return Branch::find($branchId);
            }
        }

        return $this->getDefaultBranch($user);
    }

    private function getDefaultBranch(User $user): ?Branch
    {
        $defaultBranch = $user->defaultBranch();

        if ($defaultBranch) {
            return $defaultBranch;
        }

        return Branch::where('is_active', true)
            ->whereIn('id', $user->branches()->pluck('branches.id'))
            ->first();
    }

    private function isValidBranchForUser(User $user, int $branchId): bool
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
