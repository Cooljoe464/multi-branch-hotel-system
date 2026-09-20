<?php

use App\Http\Controllers\PaystackWebhookController;
use App\Http\Controllers\PosController;
use Illuminate\Support\Facades\Route;

Route::post('/pos/charge', [PosController::class, 'charge'])
    ->middleware(['auth:sanctum', 'permission:folios.manage', 'throttle:pos'])
    ->name('api.pos.charge');

Route::post('/webhooks/paystack', [PaystackWebhookController::class, 'handle'])
    ->name('api.webhooks.paystack');
