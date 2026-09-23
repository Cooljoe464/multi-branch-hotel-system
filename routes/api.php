<?php

use App\Http\Controllers\Api\V1\AvailabilityController;
use App\Http\Controllers\Api\V1\FolioController;
use App\Http\Controllers\Api\V1\RatesController;
use App\Http\Controllers\Api\V1\ReservationController;
use App\Http\Controllers\Api\V1\RevenueController;
use App\Http\Controllers\Api\V1\WebhookController;
use App\Http\Controllers\ChannelWebhookController;
use App\Http\Controllers\PaystackWebhookController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\RevenueReportController;
use App\Http\Middleware\RequireIdempotencyKey;
use Illuminate\Support\Facades\Route;

Route::post('/pos/charge', [PosController::class, 'charge'])
    ->middleware(['auth:sanctum', 'permission:folios.manage', 'throttle:pos'])
    ->name('api.pos.charge');

Route::post('/webhooks/paystack', [PaystackWebhookController::class, 'handle'])
    ->name('api.webhooks.paystack');

Route::post('/webhooks/channels/{channelProvider}', [ChannelWebhookController::class, 'store'])
    ->name('api.webhooks.channels');

Route::get('/v1/reports/revenue', [RevenueReportController::class, 'api'])
    ->middleware(['auth:sanctum', 'permission:analytics.view'])
    ->name('api.v1.reports.revenue');

// Versioned partner API. Tokens are issued against ApiConsumer with
// `branch:{id}` abilities; branch.scope resolves the property from
// the token (single) or X-Branch-Id (multi). Writes ride the shared
// idempotency middleware: missing key → 422, replay → stored reply.
Route::prefix('v1')->middleware(['auth:sanctum', 'branch.scope'])->group(function () {
    Route::get('/availability', [AvailabilityController::class, 'show'])
        ->middleware('ability:availability.view')
        ->name('api.v1.availability');

    Route::post('/reservations', [ReservationController::class, 'store'])
        ->middleware(['ability:reservations.create', RequireIdempotencyKey::class])
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
        ->middleware('ability:analytics.view')
        ->name('api.v1.revenue');

    Route::get('/webhooks/deliveries', [WebhookController::class, 'index'])
        ->middleware('ability:webhooks.replay')
        ->name('api.v1.webhooks.deliveries');

    Route::post('/webhooks/replay', [WebhookController::class, 'replay'])
        ->middleware(['ability:webhooks.replay', RequireIdempotencyKey::class])
        ->name('api.v1.webhooks.replay');
});
