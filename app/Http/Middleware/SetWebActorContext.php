<?php

namespace App\Http\Middleware;

use App\Support\TenantDatabaseContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SetWebActorContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $userId = null;

        if ($request->hasSession()) {
            $sessionUserId = $request->session()->get(Auth::guard('web')->getName());
            $validatedUserId = filter_var($sessionUserId, FILTER_VALIDATE_INT);
            $userId = is_int($validatedUserId) && $validatedUserId > 0 ? $validatedUserId : null;
        }

        $loginEmail = $request->routeIs('login', 'password.email', 'password.update', 'register')
            ? $request->string('email')->value()
            : null;

        return app(TenantDatabaseContext::class)->run(
            $userId,
            null,
            function () use ($request, $next): Response {
                $response = $next($request);

                if (! $response instanceof Response) {
                    abort(500);
                }

                return $response;
            },
            actorType: $userId === null ? 'guest' : 'user',
            loginEmail: $loginEmail !== '' ? $loginEmail : null,
        );
    }
}
