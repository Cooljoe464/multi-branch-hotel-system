<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Services\EntitlementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks a route unless the current tenant is entitled to every listed key.
 *
 * The tenant comes from the branch resolved by SetBranchContext (or the
 * Sanctum branch scope), so this must run after it. When no branch can be
 * resolved there is no tenant to bill, so the request passes.
 */
class EnsureEntitled
{
    public function __construct(private EntitlementService $entitlements) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$keys): Response
    {
        $tenantId = $this->resolveTenantId($request);

        foreach ($keys as $key) {
            if ($this->entitlements->allows($tenantId, $key)) {
                continue;
            }

            $message = "Your subscription does not include [{$key}].";

            if ($request->expectsJson()) {
                return response()->json(['message' => $message, 'entitlement' => $key], 403);
            }

            abort(403, $message);
        }

        return $next($request);
    }

    private function resolveTenantId(Request $request): ?int
    {
        $branch = $request->route('branch');

        if (! $branch instanceof Branch) {
            $branch = $request->input('_branch');
        }

        if (! $branch instanceof Branch) {
            $branch = $request->attributes->get('branch');
        }

        return $branch instanceof Branch ? $branch->tenant_id : null;
    }
}
