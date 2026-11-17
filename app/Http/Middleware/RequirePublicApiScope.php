<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePublicApiScope
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $key = $request->attributes->get('public_api_key');

        abort_unless($key instanceof ApiKey && in_array($scope, $key->scopes, true), 403, 'The API key does not have the required scope.');

        if (in_array($scope, ['payments.create', 'reservations.create', 'reservations.view', 'reservations.cancel'], true)) {
            abort_unless($key->type === 'secret', 403, 'This API scope requires a server-side secret key.');
        }

        return $next($request);
    }
}
