<?php

use App\Http\Controllers\Api\V1\AvailabilityController;
use App\Http\Controllers\Api\V1\EdgeAgentController;
use App\Http\Controllers\Api\V1\FolioController;
use App\Http\Controllers\Api\V1\ForecastController as ForecastApiController;
use App\Http\Controllers\Api\V1\OfflineSyncController;
use App\Http\Controllers\Api\V1\RatesController;
use App\Http\Controllers\Api\V1\ReservationController;
use App\Http\Controllers\Api\V1\RevenueController;
use App\Http\Controllers\Api\V1\SaasController;
use App\Http\Controllers\Api\V1\WebhookController;
use App\Http\Controllers\CdrController;
use App\Http\Controllers\ChannelWebhookController;
use App\Http\Controllers\ConciergeReportController;
use App\Http\Controllers\FlutterwaveWebhookController;
use App\Http\Controllers\OfflineConflictController;
use App\Http\Controllers\PaystackWebhookController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\RevenueReportController;
use App\Http\Controllers\Scim\ScimUserController;
use App\Http\Controllers\WarehouseController as WarehouseApiController;
use App\Http\Controllers\Webhooks\TermiiWebhookController;
use App\Http\Controllers\WifiPortalController;
use App\Http\Middleware\AuthenticateEdgeAgent;
use App\Http\Middleware\RequireIdempotencyKey;
use App\Http\Middleware\SetBranchContext;
use App\Http\Middleware\SetSanctumActorContext;
use Illuminate\Support\Facades\Route;

Route::post('/pos/charge', [PosController::class, 'charge'])
    ->middleware([SetSanctumActorContext::class, 'auth:sanctum', SetBranchContext::class, 'permission:folios.manage', 'throttle:pos'])
    ->name('api.pos.charge');

Route::post('/webhooks/paystack', [PaystackWebhookController::class, 'handle'])
    ->middleware('throttle:inbound-webhook')
    ->name('api.webhooks.paystack');

Route::post('/webhooks/flutterwave', [FlutterwaveWebhookController::class, 'handle'])
    ->middleware('throttle:inbound-webhook')
    ->name('api.webhooks.flutterwave');

Route::post('/webhooks/termii/dsr', [TermiiWebhookController::class, 'handleDsr'])
    ->middleware('throttle:inbound-webhook')
    ->name('api.webhooks.termii.dsr');

Route::post('/cdr/{branch}', [CdrController::class, 'store'])
    ->middleware('throttle:60,1')
    ->name('api.cdr.store');

Route::post('/wifi/validate', [WifiPortalController::class, 'validate'])
    ->middleware('throttle:60,1')
    ->name('api.wifi.validate');

Route::post('/webhooks/channels/{channelProvider}', [ChannelWebhookController::class, 'store'])
    ->middleware('throttle:inbound-webhook')
    ->name('api.webhooks.channels');

Route::get('/v1/reports/revenue', [RevenueReportController::class, 'api'])
    ->middleware([SetSanctumActorContext::class, 'auth:sanctum', SetBranchContext::class, 'permission:analytics.view'])
    ->name('api.v1.reports.revenue');

Route::get('/v1/reports/concierge', [ConciergeReportController::class, 'api'])
    ->middleware([SetSanctumActorContext::class, 'auth:sanctum', SetBranchContext::class, 'permission:analytics.view'])
    ->name('api.v1.reports.concierge');

// Versioned partner API. Tokens are issued against ApiConsumer with
// `branch:{id}` abilities; branch.scope resolves the property from
// the token (single) or X-Branch-Id (multi). Writes ride the shared
// idempotency middleware: missing key → 422, replay → stored reply.
Route::prefix('v1')->middleware([SetSanctumActorContext::class, 'auth:sanctum', 'branch.scope'])->group(function () {
    Route::get('/availability', [AvailabilityController::class, 'show'])
        ->middleware('ability:availability.view')
        ->name('api.v1.availability');

    Route::post('/reservations', [ReservationController::class, 'store'])
        ->middleware(['ability:reservations.create', 'entitled:pms.reservations', RequireIdempotencyKey::class])
        ->name('api.v1.reservations.store');

    Route::get('/reservations/{id}', [ReservationController::class, 'show'])
        ->middleware('ability:reservations.view')
        ->name('api.v1.reservations.show');

    Route::post('/reservations/{id}/cancel', [ReservationController::class, 'cancel'])
        ->middleware(['ability:reservations.cancel', RequireIdempotencyKey::class])
        ->name('api.v1.reservations.cancel');

    Route::get('/folios/{id}', [FolioController::class, 'show'])
        ->middleware('ability:folios.view')
        ->name('api.v1.folios.show');

    Route::get('/rates', [RatesController::class, 'index'])
        ->middleware('ability:rate_plans.view')
        ->name('api.v1.rates');

    Route::get('/reports/revenue', [RevenueController::class, 'show'])
        ->middleware(['ability:analytics.view', 'replica'])
        ->name('api.v1.revenue');

    Route::get('/forecasts', [ForecastApiController::class, 'index'])
        ->middleware(['ability:analytics.view', 'replica'])
        ->name('api.v1.forecasts');

    Route::get('/me/entitlements', [SaasController::class, 'entitlements'])
        ->middleware('ability:saas.view')
        ->name('api.v1.me.entitlements');

    Route::get('/saas/subscription', [SaasController::class, 'subscription'])
        ->middleware('ability:saas.view')
        ->name('api.v1.saas.subscription');

    Route::get('/saas/invoices', [SaasController::class, 'invoices'])
        ->middleware('ability:saas.view')
        ->name('api.v1.saas.invoices');

    Route::get('/webhooks/deliveries', [WebhookController::class, 'index'])
        ->middleware('ability:webhooks.replay')
        ->name('api.v1.webhooks.deliveries');

    Route::post('/webhooks/replay', [WebhookController::class, 'replay'])
        ->middleware(['ability:webhooks.replay', RequireIdempotencyKey::class])
        ->name('api.v1.webhooks.replay');

    Route::get('/warehouse/manifests', [WarehouseApiController::class, 'manifests'])
        ->middleware('ability:analytics.export_warehouse')
        ->name('api.v1.warehouse.manifests');
});

Route::prefix('v1/sync')->middleware([SetSanctumActorContext::class, 'auth:sanctum', SetBranchContext::class])->group(function () {
    Route::post('/push', [OfflineSyncController::class, 'push'])
        ->middleware(RequireIdempotencyKey::class)
        ->name('api.v1.sync.push');
    Route::get('/pull', [OfflineSyncController::class, 'pull'])->name('api.v1.sync.pull');
    Route::post('/override-token', [OfflineSyncController::class, 'issueOverride'])
        ->middleware('permission:reservations.checkin')
        ->name('api.v1.sync.override-token');
});

Route::prefix('v1/conflicts')->middleware([SetSanctumActorContext::class, 'auth:sanctum', SetBranchContext::class])->group(function () {
    Route::get('/', [OfflineConflictController::class, 'index'])
        ->middleware('permission:offline.review')
        ->name('api.v1.conflicts.index');
    Route::post('/{command}/resolve', [OfflineConflictController::class, 'resolve'])
        ->middleware('permission:offline.review')
        ->name('api.v1.conflicts.resolve');
    Route::post('/{command}/discard', [OfflineConflictController::class, 'discard'])
        ->middleware('permission:offline.review')
        ->name('api.v1.conflicts.discard');
});

Route::prefix('v1/edge')->middleware(AuthenticateEdgeAgent::class)->group(function () {
    Route::post('/heartbeat', [EdgeAgentController::class, 'heartbeat'])->name('api.v1.edge.heartbeat');
    Route::get('/policy', [EdgeAgentController::class, 'policy'])->name('api.v1.edge.policy');
});

Route::middleware(['api', SetSanctumActorContext::class, 'auth:sanctum', 'ability:scim.manage'])->prefix('scim/v2')->group(function () {
    Route::get('/Users', [ScimUserController::class, 'index'])->name('scim.v2.users.index');
    Route::post('/Users', [ScimUserController::class, 'store'])->name('scim.v2.users.store');
    Route::get('/Users/{user}', [ScimUserController::class, 'show'])->name('scim.v2.users.show');
    Route::put('/Users/{user}', [ScimUserController::class, 'update'])->name('scim.v2.users.update');
    Route::patch('/Users/{user}', [ScimUserController::class, 'patch'])->name('scim.v2.users.patch');
    Route::delete('/Users/{user}', [ScimUserController::class, 'destroy'])->name('scim.v2.users.destroy');
});
