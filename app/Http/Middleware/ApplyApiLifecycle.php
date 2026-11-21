<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * RFC 8594 style API lifecycle headers for deprecated endpoints.
 *
 * Reads the route name against the `public_api.lifecycle.deprecated`
 * registry (route name => sunset policy) and stamps `Deprecation` plus
 * `Sunset` on the outgoing response so well-behaved API clients retry
 * or migrate before the announced retirement date.
 */
class ApplyApiLifecycle
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $route = $request->route();

        $policy = $route instanceof Route
            ? (config('public_api.lifecycle.deprecated')[$route->getName()] ?? null)
            : null;

        if (! is_array($policy)) {
            return $response;
        }

        $response->headers->set('Deprecation', 'true');

        if (isset($policy['sunset_at']) && $policy['sunset_at'] !== null) {
            $response->headers->set('Sunset', $policy['sunset_at']);
        }

        if (! empty($policy['message'])) {
            $response->headers->set('X-API-Deprecation', (string) $policy['message']);
        }

        return $response;
    }
}
