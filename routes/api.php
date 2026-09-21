<?php

use App\Http\Controllers\ChannelWebhookController;
use App\Http\Controllers\PaystackWebhookController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\RevenueReportController;
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
