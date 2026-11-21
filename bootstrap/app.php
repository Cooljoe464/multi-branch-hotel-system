<?php

use App\Exceptions\AvailabilityException;
use App\Exceptions\StaleModelException;
use App\Http\Controllers\Api\V1\PublicBookingController;
use App\Http\Controllers\Api\V1\PublicInventoryHoldController;
use App\Http\Controllers\PaymentConnectionWebhookController;
use App\Http\Middleware\ApiBranchScope;
use App\Http\Middleware\ApplyApiLifecycle;
use App\Http\Middleware\AuthenticateEdgeAgent;
use App\Http\Middleware\AuthenticatePublicApiKey;
use App\Http\Middleware\EnforceMfa;
use App\Http\Middleware\EnsureEntitled;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ReadFromReplica;
use App\Http\Middleware\RequireIdempotencyKey;
use App\Http\Middleware\RequirePublicApiScope;
use App\Http\Middleware\SetBranchContext;
use App\Http\Middleware\SetSanctumActorContext;
use App\Http\Middleware\SetWebActorContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function (): void {
            Route::middleware(['api', AuthenticatePublicApiKey::class])->prefix('public/v1')->group(function (): void {
                Route::get('/properties/{propertyId}', [PublicBookingController::class, 'property'])
                    ->middleware('public-api.scope:availability.view')
                    ->name('public.v1.properties.show');
                Route::get('/room-types', [PublicBookingController::class, 'roomTypes'])
                    ->middleware('public-api.scope:availability.view')
                    ->name('public.v1.room-types.index');
                Route::get('/availability', [PublicBookingController::class, 'availability'])
                    ->middleware('public-api.scope:availability.view')
                    ->name('public.v1.availability');
                Route::post('/quotes', [PublicBookingController::class, 'quote'])
                    ->middleware(['public-api.scope:availability.view', RequireIdempotencyKey::class])
                    ->name('public.v1.quotes.store');
                Route::post('/payments/intents', [PublicBookingController::class, 'paymentIntent'])
                    ->middleware(['public-api.scope:payments.create', RequireIdempotencyKey::class])
                    ->name('public.v1.payments.intents.store');
                Route::post('/reservations', [PublicBookingController::class, 'store'])
                    ->middleware(['public-api.scope:reservations.create', RequireIdempotencyKey::class])
                    ->name('public.v1.reservations.store');
                Route::get('/reservations/{confirmationNumber}', [PublicBookingController::class, 'reservation'])
                    ->middleware('public-api.scope:reservations.view')
                    ->name('public.v1.reservations.show');
                Route::post('/reservations/{confirmationNumber}/cancel', [PublicBookingController::class, 'cancelReservation'])
                    ->middleware(['public-api.scope:reservations.cancel', RequireIdempotencyKey::class])
                    ->name('public.v1.reservations.cancel');

                Route::options('/holds', fn () => response()->noContent());
                Route::options('/holds/{hold}', fn () => response()->noContent());

                Route::post('/holds', [PublicInventoryHoldController::class, 'store'])
                    ->middleware(['public-api.scope:holds.create', RequireIdempotencyKey::class])
                    ->name('public.v1.holds.store');

                Route::delete('/holds/{hold}', [PublicInventoryHoldController::class, 'destroy'])
                    ->middleware(['public-api.scope:holds.release', RequireIdempotencyKey::class])
                    ->name('public.v1.holds.destroy');
            });

            Route::middleware(['api', 'throttle:120,1'])->prefix('webhooks')->post('/{provider}/{connectionId}', [PaymentConnectionWebhookController::class, 'handle'])
                ->whereIn('provider', ['paystack', 'flutterwave'])
                ->whereNumber('connectionId')
                ->name('webhooks.payment.connection');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->validateCsrfTokens(except: [
            'webhooks/whatsapp',
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            'branch.scope' => ApiBranchScope::class,
            'entitled' => EnsureEntitled::class,
            'replica' => ReadFromReplica::class,
            'edge.agent' => AuthenticateEdgeAgent::class,
            'public-api.scope' => RequirePublicApiScope::class,
            'mfa' => EnforceMfa::class,
        ]);

        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            SetBranchContext::class,
        );
        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            AuthenticatePublicApiKey::class,
        );
        $middleware->prependToPriorityList(
            AuthenticatesRequests::class,
            SetSanctumActorContext::class,
        );
        $middleware->appendToPriorityList(
            StartSession::class,
            SetWebActorContext::class,
        );

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            SetBranchContext::class,
            SetWebActorContext::class,
            RequireIdempotencyKey::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->api(append: [
            ApplyApiLifecycle::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('public/*') || $request->expectsJson(),
        );

        $exceptions->render(function (StaleModelException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*') || $request->is('public/*')) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'code' => 'STALE_VERSION',
                ], 409);
            }

            return back()->withErrors(['version' => $e->getMessage()]);
        });

        $exceptions->render(function (AvailabilityException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*') || $request->is('public/*')) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'code' => $e->availabilityCode,
                    'unavailable_dates' => $e->unavailableDates,
                ], $e->availabilityCode === 'OVERBOOK_FORBIDDEN' ? 403 : 422);
            }

            return back()->withErrors(['room_id' => $e->getMessage()]);
        });

        // RFC 9457 problem+json for API consumers. Specific exception types
        // register render callbacks above; this catch-all answers JSON clients
        // and lets non-JSON requests fall through to the default renderer.
        $exceptions->render(function (Throwable $exception, Request $request): ?Response {
            if (! ($request->expectsJson() || $request->is('api/*') || $request->is('public/*'))) {
                return null;
            }

            $status = match (true) {
                $exception instanceof ValidationException => 422,
                $exception instanceof AuthenticationException => 401,
                $exception instanceof AuthorizationException => 403,
                $exception instanceof HttpException => $exception->getStatusCode(),
                default => $exception->status ?? 500,
            };
            $isClientError = $status >= 400 && $status < 500;

            $problem = [
                'type' => $exception instanceof AvailabilityException
                    ? 'https://schemas.hospitalityos.dev/problems/'.$exception->availabilityCode
                    : 'about:blank',
                'title' => $isClientError ? $exception->getMessage() : 'The request could not be processed.',
                'status' => $status,
                'detail' => $exception->getMessage(),
            ];

            if ($exception instanceof ValidationException) {
                $problem['errors'] = $exception->errors();
            }

            return response()->json($problem, $status, [
                'Content-Type' => 'application/problem+json',
            ]);
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
