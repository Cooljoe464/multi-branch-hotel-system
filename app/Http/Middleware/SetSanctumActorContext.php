<?php

namespace App\Http\Middleware;

use App\Models\ApiConsumer;
use App\Models\User;
use App\Support\TenantDatabaseContext;
use Closure;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class SetSanctumActorContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $plainTextToken = $request->bearerToken();

        if (! is_string($plainTextToken) || $plainTextToken === '') {
            return $this->next($request, $next);
        }

        $accessToken = PersonalAccessToken::findToken($plainTextToken);

        if (! $accessToken) {
            return $this->next($request, $next);
        }

        $tokenableType = (string) $accessToken->tokenable_type;
        $tokenableClass = Relation::getMorphedModel($tokenableType) ?? $tokenableType;
        $actorType = match ($tokenableClass) {
            ApiConsumer::class => 'api_consumer',
            User::class => 'user',
            default => null,
        };
        $actorId = filter_var($accessToken->tokenable_id, FILTER_VALIDATE_INT);

        if ($actorType === null || ! is_int($actorId) || $actorId < 1) {
            return $this->next($request, $next);
        }

        return app(TenantDatabaseContext::class)->run(
            $actorId,
            null,
            fn (): Response => $this->next($request, $next),
            actorType: $actorType,
        );
    }

    private function next(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof Response) {
            abort(500);
        }

        return $response;
    }
}
