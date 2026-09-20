<?php

use App\Http\Controllers\BrandingController;
use App\Http\Controllers\Settings\CashierOutletController;
use App\Http\Controllers\Settings\CurrencyController;
use App\Http\Controllers\Settings\PaymentGuardController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::get('settings/appearance', fn () => inertia('settings/Appearance'))->name('appearance.edit');

    // Branding (Global Admin only)
    Route::middleware(['auth', 'verified'])->group(function () {
        Route::get('settings/branding', [BrandingController::class, 'edit'])
            ->middleware('permission:branches.manage')
            ->name('branding.edit');
        Route::put('settings/branding', [BrandingController::class, 'update'])
            ->middleware('permission:branches.manage')
            ->name('branding.update');
        Route::delete('settings/branding/logo', [BrandingController::class, 'destroyLogo'])
            ->middleware('permission:branches.manage')
            ->name('branding.destroy-logo');
    });

    // Currency (Global Admin only)
    Route::middleware(['auth', 'verified'])->group(function () {
        Route::get('settings/currency', [CurrencyController::class, 'edit'])
            ->middleware('permission:branches.manage')
            ->name('currency.edit');
        Route::put('settings/currency', [CurrencyController::class, 'update'])
            ->middleware('permission:branches.manage')
            ->name('currency.update');
    });

    // Payment Guard (Global Admin / Settings Manager only)
    Route::middleware(['auth', 'verified'])->group(function () {
        Route::get('settings/payment-guard', [PaymentGuardController::class, 'edit'])
            ->middleware('permission:settings.manage')
            ->name('payment-guard.edit');
        Route::put('settings/payment-guard', [PaymentGuardController::class, 'update'])
            ->middleware('permission:settings.manage')
            ->name('payment-guard.update');
    });

    // Cashier POS Outlet Access (Outlet Managers only)
    Route::middleware(['auth', 'verified'])->group(function () {
        Route::get('settings/cashier-outlets', [CashierOutletController::class, 'index'])
            ->middleware('permission:outlets.manage')
            ->name('settings.cashier-outlets.index');
        Route::put('settings/cashier-outlets/{cashier}', [CashierOutletController::class, 'update'])
            ->middleware('permission:outlets.manage')
            ->name('settings.cashier-outlets.update');
    });
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
