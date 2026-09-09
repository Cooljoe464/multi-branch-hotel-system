<?php

use App\Http\Controllers\BookingEngineController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\GuestPortalController;
use App\Http\Controllers\HousekeepingController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\TapeChartController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

// Public routes - Booking Engine
Route::get('book', [BookingEngineController::class, 'index'])->name('booking.index');
Route::post('book/search', [BookingEngineController::class, 'search'])->name('booking.search');
Route::post('book', [BookingEngineController::class, 'store'])->name('booking.store');
Route::get('book/availability', [BookingEngineController::class, 'availability'])->name('booking.availability');

// Public routes - Guest Portal
Route::get('guest/folio/{confirmationNumber}', [GuestPortalController::class, 'folio'])->name('guest.folio');
Route::post('guest/lookup', [GuestPortalController::class, 'searchGuest'])->name('guest.lookup');
Route::get('guest/checkin/{confirmationNumber}', [GuestPortalController::class, 'folio'])->name('guest.checkin');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::post('branch/switch', [BranchController::class, 'switch'])->name('branch.switch');

    // Tape Chart
    Route::get('tape-chart', [TapeChartController::class, 'index'])->name('tape-chart.index');

    // Rooms
    Route::get('rooms', [RoomController::class, 'index'])->name('rooms.index');
    Route::post('rooms', [RoomController::class, 'store'])->name('rooms.store');
    Route::put('rooms/{room}', [RoomController::class, 'update'])->name('rooms.update');
    Route::patch('rooms/{room}/status', [RoomController::class, 'updateStatus'])->name('rooms.update-status');
    Route::delete('rooms/{room}', [RoomController::class, 'destroy'])->name('rooms.destroy');

    // Reservations
    Route::get('reservations', [ReservationController::class, 'index'])->name('reservations.index');
    Route::get('reservations/create', [ReservationController::class, 'create'])->name('reservations.create');
    Route::post('reservations', [ReservationController::class, 'store'])->name('reservations.store');
    Route::get('reservations/{reservation}', [ReservationController::class, 'show'])->name('reservations.show');
    Route::get('reservations/{reservation}/edit', [ReservationController::class, 'edit'])->name('reservations.edit');
    Route::put('reservations/{reservation}', [ReservationController::class, 'update'])->name('reservations.update');
    Route::post('reservations/{reservation}/check-in', [ReservationController::class, 'checkIn'])->name('reservations.check-in');
    Route::post('reservations/{reservation}/check-out', [ReservationController::class, 'checkOut'])->name('reservations.check-out');
    Route::post('reservations/{reservation}/cancel', [ReservationController::class, 'cancel'])->name('reservations.cancel');
    Route::delete('reservations/{reservation}', [ReservationController::class, 'destroy'])->name('reservations.destroy');

    // Cross-Branch Search
    Route::get('reservations/cross-branch/search', [ReservationController::class, 'crossBranchSearch'])->name('reservations.cross-branch');

    // Housekeeping
    Route::get('housekeeping', [HousekeepingController::class, 'index'])->name('housekeeping.index');
    Route::post('housekeeping', [HousekeepingController::class, 'store'])->name('housekeeping.store');
    Route::put('housekeeping/{task}', [HousekeepingController::class, 'update'])->name('housekeeping.update');
    Route::post('housekeeping/{task}/start', [HousekeepingController::class, 'start'])->name('housekeeping.start');
    Route::post('housekeeping/{task}/complete', [HousekeepingController::class, 'complete'])->name('housekeeping.complete');
    Route::delete('housekeeping/{task}', [HousekeepingController::class, 'destroy'])->name('housekeeping.destroy');
    Route::get('housekeeping/mobile', [HousekeepingController::class, 'mobile'])->name('housekeeping.mobile');

    // Maintenance
    Route::get('maintenance', [MaintenanceController::class, 'index'])->name('maintenance.index');
    Route::get('maintenance/create', [MaintenanceController::class, 'create'])->name('maintenance.create');
    Route::post('maintenance', [MaintenanceController::class, 'store'])->name('maintenance.store');
    Route::get('maintenance/{ticket}', [MaintenanceController::class, 'show'])->name('maintenance.show');
    Route::put('maintenance/{ticket}', [MaintenanceController::class, 'update'])->name('maintenance.update');
    Route::post('maintenance/{ticket}/start', [MaintenanceController::class, 'start'])->name('maintenance.start');
    Route::post('maintenance/{ticket}/complete', [MaintenanceController::class, 'complete'])->name('maintenance.complete');
    Route::post('maintenance/{ticket}/lock-room', [MaintenanceController::class, 'lockRoom'])->name('maintenance.lock-room');
    Route::post('maintenance/{ticket}/unlock-room', [MaintenanceController::class, 'unlockRoom'])->name('maintenance.unlock-room');
    Route::delete('maintenance/{ticket}', [MaintenanceController::class, 'destroy'])->name('maintenance.destroy');
});

require __DIR__.'/settings.php';
