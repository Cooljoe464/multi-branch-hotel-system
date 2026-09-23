<?php

namespace App\Http\Middleware;

use App\Models\ApiConsumer;
use App\Models\Branch;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Branch isolation for versioned API tokens. The token's frozen
 * `branch:{id}` abilities intersect the consumer's current reach,
 * so revoking a property cuts existing tokens. Single-property
 * consumers lock to their branch; multi-property consumers must
 * send X-Branch-Id. The resolved branch rides the request.
 */
class ApiBranchScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            abort(401, 'Invalid API token.');
        }

        $consumer = $token->tokenable;

        if (! $consumer instanceof ApiConsumer || ! $consumer->is_active) {
            abort(401, 'Invalid API token.');
        }

        $tokenBranches = [];
        $abilities = $token->abilities ?? [];
        foreach ($abilities as $ability) {
            if (is_string($ability) && str_starts_with($ability, 'branch:')) {
                $id = (int) substr($ability, 7);
                if ($id > 0) {
                    $tokenBranches[] = $id;
                }
            }
        }

        $allowed = array_values(array_intersect($tokenBranches, $consumer->reachableBranchIds()));

        if ($allowed === []) {
            abort(403, 'This token reaches no property.');
        }

        $requested = $request->header('X-Branch-Id');

        if (is_string($requested) && $requested !== '') {
            $branchId = (int) $requested;

            if (! in_array($branchId, $allowed, true)) {
                abort(403, 'Branch is outside this token\'s scope.');
            }
        } elseif (count($allowed) === 1) {
            $branchId = $allowed[0];
        } else {
            abort(422, 'X-Branch-Id is required for multi-property tokens.');
        }

        $branch = Branch::where('id', $branchId)->where('is_active', true)->first();

        if (! $branch) {
            abort(403, 'Branch is outside this token\'s scope.');
        }

        $request->attributes->set('branch', $branch);
        $request->attributes->set('api_consumer', $consumer);

        $response = $next($request);
        if (! $response instanceof Response) {
            abort(500);
        }

        return $response;
    }
}
