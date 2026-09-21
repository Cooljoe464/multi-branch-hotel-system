<?php

use App\Exceptions\AvailabilityException;
use App\Exceptions\StaleModelException;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireIdempotencyKey;
use App\Http\Middleware\SetBranchContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            SetBranchContext::class,
            RequireIdempotencyKey::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (StaleModelException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'code' => 'STALE_VERSION',
                ], 409);
            }

            return back()->withErrors(['version' => $e->getMessage()]);
        });

        $exceptions->render(function (AvailabilityException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'code' => $e->availabilityCode,
                    'unavailable_dates' => $e->unavailableDates,
                ], $e->availabilityCode === 'OVERBOOK_FORBIDDEN' ? 403 : 422);
            }

            return back()->withErrors(['room_id' => $e->getMessage()]);
        });

        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            if (! $request->expectsJson()
                && ! $request->is('api/*')
                && $request->header('X-Inertia')) {
                if ($response->getStatusCode() === 403) {
                    return Inertia::render('errors/403')->toResponse($request)->setStatusCode(403);
                }

                if ($response->getStatusCode() === 404) {
                    return Inertia::render('errors/404')->toResponse($request)->setStatusCode(404);
                }

                if ($response->getStatusCode() >= 500) {
                    return Inertia::render('errors/500')->toResponse($request)->setStatusCode(500);
                }
            }

            return $response;
        });
    })->create();
