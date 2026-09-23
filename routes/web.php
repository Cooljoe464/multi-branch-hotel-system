<?php

use App\Http\Controllers\AccountingExportController;
use App\Http\Controllers\Admin\BranchWizardController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SystemController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\AuditFlagController;
use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\BankProfileController;
use App\Http\Controllers\BeoController;
use App\Http\Controllers\BookingEngineController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\BusinessDateController;
use App\Http\Controllers\CashierShiftController;
use App\Http\Controllers\ChannelManagerController;
use App\Http\Controllers\ChannelMappingController;
use App\Http\Controllers\ChannelMessageController;
use App\Http\Controllers\ChartAccountController;
use App\Http\Controllers\CityLedgerController;
use App\Http\Controllers\CommercialController;
use App\Http\Controllers\CommissionController;
use App\Http\Controllers\ConsentController;
use App\Http\Controllers\CrsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DevelopersController;
use App\Http\Controllers\DiningTableController;
use App\Http\Controllers\DoNotRentController;
use App\Http\Controllers\DsarController;
use App\Http\Controllers\FolioController;
use App\Http\Controllers\FolioDisputeController;
use App\Http\Controllers\FolioWindowController;
use App\Http\Controllers\FrontDeskDashboardController;
use App\Http\Controllers\FunctionSpaceController;
use App\Http\Controllers\GdprController;
use App\Http\Controllers\GoodsReceiptController;
use App\Http\Controllers\GroupBlockController;
use App\Http\Controllers\GroupLedgerController;
use App\Http\Controllers\GuaranteePolicyController;
use App\Http\Controllers\GuestMergeController;
use App\Http\Controllers\GuestOrderController;
use App\Http\Controllers\GuestPaymentController;
use App\Http\Controllers\GuestPortalController;
use App\Http\Controllers\GuestProfileController;
use App\Http\Controllers\HappyHourController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HousekeepingController;
use App\Http\Controllers\HousekeepingTaskController;
use App\Http\Controllers\IdempotencyController;
use App\Http\Controllers\IdentityDocumentController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\ImportTemplateController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\JournalController;
use App\Http\Controllers\KdsController;
use App\Http\Controllers\KitchenWasteController;
use App\Http\Controllers\LaundryController;
use App\Http\Controllers\LostFoundController;
use App\Http\Controllers\LoyaltyController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\MenuItemController;
use App\Http\Controllers\MinibarController;
use App\Http\Controllers\NightAuditController;
use App\Http\Controllers\OutletController;
use App\Http\Controllers\PosModifierController;
use App\Http\Controllers\PosTabController;
use App\Http\Controllers\PosTerminalController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\RateOverrideController;
use App\Http\Controllers\RatePlanController;
use App\Http\Controllers\RateRestrictionController;
use App\Http\Controllers\RegistrationCardController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\ReservationMoveController;
use App\Http\Controllers\RetentionPolicyController;
use App\Http\Controllers\RevenueReportController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\StatutoryReportController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SurveyController;
use App\Http\Controllers\TabletMenuController;
use App\Http\Controllers\TabletOrderController;
use App\Http\Controllers\TabletSessionController;
use App\Http\Controllers\TapeChartController;
use App\Http\Controllers\TaxProfileController;
use App\Http\Controllers\TransferController;
use App\Http\Controllers\TrialBalanceController;
use App\Http\Controllers\UpsellAcceptanceController;
use App\Http\Controllers\UpsellOfferController;
use App\Http\Controllers\VoidRefundController;
use App\Http\Controllers\WarehouseController;
use App\Http\Controllers\WorkOrderController;
use App\Http\Controllers\YieldRuleController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => inertia('Welcome'))->name('home');

// Liveness / readiness (unauthenticated, rate-limited for monitors)
Route::middleware('throttle:60,1')->group(function () {
    Route::get('healthz', [HealthController::class, 'live'])->name('health.live');
    Route::get('readyz', [HealthController::class, 'ready'])->name('health.ready');
    Route::get('health/queues', [HealthController::class, 'queues'])->name('health.queues');
});

// Public routes - Booking Engine (rate limited)
Route::middleware('throttle:booking')->group(function () {
    Route::get('book', [BookingEngineController::class, 'index'])->name('booking.index');
    Route::match(['get', 'post'], 'book/search', [BookingEngineController::class, 'search'])->name('booking.search');
    Route::post('book', [BookingEngineController::class, 'store'])->name('booking.store');
    Route::get('book/availability', [BookingEngineController::class, 'availability'])->name('booking.availability');
});

// Public routes - Guest Portal
Route::get('guest', [GuestPortalController::class, 'index'])->name('guest.index');
Route::get('guest/folio/{confirmationNumber}', [GuestPortalController::class, 'folio'])->name('guest.folio');
Route::post('guest/lookup', [GuestPortalController::class, 'searchGuest'])->name('guest.lookup');
Route::get('guest/checkin/{confirmationNumber}', [GuestPortalController::class, 'folio'])->name('guest.checkin');

// Guest Ordering
Route::get('guest/order/{confirmationNumber}', [GuestOrderController::class, 'outlets'])->name('guest.order.outlets');
Route::get('guest/order/{confirmationNumber}/{outletCode}', [GuestOrderController::class, 'menu'])->name('guest.order.menu');
Route::post('guest/order/{confirmationNumber}', [GuestOrderController::class, 'store'])->name('guest.order.store');
Route::get('guest/order/{confirmationNumber}/track/{orderId}', [GuestOrderController::class, 'track'])->name('guest.order.track');

// Public routes - Post-stay surveys (guest link, no account needed)
Route::get('surveys/{reservation}', [SurveyController::class, 'show'])->name('surveys.show');
Route::post('surveys/{reservation}', [SurveyController::class, 'store'])->name('surveys.store');

// Guest Payment
Route::post('guest/folio/{confirmationNumber}/pay', [GuestPaymentController::class, 'initiate'])->name('guest.payment.initiate');
Route::get('guest/folio/{confirmationNumber}/pay/callback', [GuestPaymentController::class, 'callback'])->name('guest.payment.callback');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('front-desk', FrontDeskDashboardController::class)
        ->middleware('permission:rooms.view')
        ->name('front-desk.dashboard');

    Route::post('branch/switch', [BranchController::class, 'switch'])->name('branch.switch');

    // Availability (per-date engine)
    Route::get('branches/{branch}/availability', [AvailabilityController::class, 'quote'])
        ->middleware('permission:availability.view')
        ->name('availability.quote');

    // Rate restrictions
    Route::get('branches/{branch}/restrictions', [RateRestrictionController::class, 'index'])
        ->middleware('permission:rate_restrictions.view')
        ->name('restrictions.index');
    Route::post('branches/{branch}/restrictions', [RateRestrictionController::class, 'storeRange'])
        ->middleware('permission:rate_restrictions.manage')
        ->name('restrictions.store');

    // Commercial setup (seasons, promos, corporate)
    Route::get('branches/{branch}/commercial', [CommercialController::class, 'index'])
        ->middleware('permission:rate_seasons.view')
        ->name('commercial.index');
    Route::post('branches/{branch}/commercial/seasons', [CommercialController::class, 'storeSeason'])
        ->middleware('permission:rate_seasons.manage')
        ->name('commercial.seasons.store');
    Route::delete('branches/{branch}/commercial/seasons/{season}', [CommercialController::class, 'destroySeason'])
        ->middleware('permission:rate_seasons.manage')
        ->name('commercial.seasons.destroy');
    Route::post('branches/{branch}/commercial/promos', [CommercialController::class, 'storePromo'])
        ->middleware('permission:promo_codes.manage')
        ->name('commercial.promos.store');
    Route::delete('branches/{branch}/commercial/promos/{promo}', [CommercialController::class, 'destroyPromo'])
        ->middleware('permission:promo_codes.manage')
        ->name('commercial.promos.destroy');
    Route::post('branches/{branch}/commercial/corporate', [CommercialController::class, 'storeCorporate'])
        ->middleware('permission:corporate_accounts.manage')
        ->name('commercial.corporate.store');
    Route::delete('branches/{branch}/commercial/corporate/{corporate}', [CommercialController::class, 'destroyCorporate'])
        ->middleware('permission:corporate_accounts.manage')
        ->name('commercial.corporate.destroy');

    // Guarantee policies
    Route::get('branches/{branch}/guarantees', [GuaranteePolicyController::class, 'index'])
        ->middleware('permission:reservations.view')
        ->name('guarantees.index');
    Route::post('branches/{branch}/guarantees', [GuaranteePolicyController::class, 'store'])
        ->middleware('permission:rate_plans.manage')
        ->name('guarantees.store');
    Route::delete('branches/{branch}/guarantees/{policy}', [GuaranteePolicyController::class, 'destroy'])
        ->middleware('permission:rate_plans.manage')
        ->name('guarantees.destroy');

    // Group blocks + BEOs
    Route::get('branches/{branch}/groups', [GroupBlockController::class, 'index'])
        ->middleware('permission:groups.view')
        ->name('groups.index');
    Route::post('branches/{branch}/groups', [GroupBlockController::class, 'store'])
        ->middleware('permission:groups.manage')
        ->name('groups.store');
    Route::get('branches/{branch}/groups/{block}', [GroupBlockController::class, 'show'])
        ->middleware('permission:groups.view')
        ->name('groups.show');
    Route::post('branches/{branch}/groups/{block}/pickup', [GroupBlockController::class, 'pickup'])
        ->middleware('permission:groups.manage')
        ->name('groups.pickup');
    Route::post('branches/{branch}/groups/{block}/release', [GroupBlockController::class, 'release'])
        ->middleware('permission:groups.manage')
        ->name('groups.release');
    Route::post('branches/{branch}/groups/{block}/rooming-list', [GroupBlockController::class, 'importRoomingList'])
        ->middleware('permission:groups.manage')
        ->name('groups.rooming-list');
    Route::post('branches/{branch}/groups/{block}/beos', [BeoController::class, 'store'])
        ->middleware('permission:groups.manage_beo')
        ->name('groups.beos.store');
    Route::put('branches/{branch}/groups/beos/{beo}', [BeoController::class, 'update'])
        ->middleware('permission:groups.manage_beo')
        ->name('groups.beos.update');
    Route::post('branches/{branch}/groups/beos/{beo}/post', [BeoController::class, 'post'])
        ->middleware('permission:groups.manage_beo')
        ->name('groups.beos.post');
    Route::post('branches/{branch}/function-spaces', [FunctionSpaceController::class, 'store'])
        ->middleware('permission:groups.manage')
        ->name('function-spaces.store');
    Route::delete('branches/{branch}/function-spaces/{space}', [FunctionSpaceController::class, 'destroy'])
        ->middleware('permission:groups.manage')
        ->name('function-spaces.destroy');

    // Housekeeping depth (tasks, conditions, OOO, minibar, lost & found)
    Route::get('branches/{branch}/housekeeping/board', [HousekeepingTaskController::class, 'index'])
        ->middleware('permission:housekeeping.view')
        ->name('hk.board');
    Route::post('branches/{branch}/housekeeping/tasks', [HousekeepingTaskController::class, 'store'])
        ->middleware('permission:housekeeping.manage')
        ->name('hk.tasks.store');
    Route::post('branches/{branch}/housekeeping/tasks/{task}/assign', [HousekeepingTaskController::class, 'assign'])
        ->middleware('permission:housekeeping.assign')
        ->name('hk.tasks.assign');
    Route::post('branches/{branch}/housekeeping/auto-allocate', [HousekeepingTaskController::class, 'autoAllocate'])
        ->middleware('permission:housekeeping.assign')
        ->name('hk.auto-allocate');
    Route::post('branches/{branch}/housekeeping/tasks/{task}/complete', [HousekeepingTaskController::class, 'complete'])
        ->middleware('permission:housekeeping.manage')
        ->name('hk.tasks.complete');
    Route::post('branches/{branch}/housekeeping/rooms/{room}/condition', [HousekeepingTaskController::class, 'setCondition'])
        ->middleware('permission:housekeeping.manage')
        ->name('hk.rooms.condition');
    Route::post('branches/{branch}/housekeeping/rooms/{room}/out-of-order', [HousekeepingTaskController::class, 'storeOutOfOrder'])
        ->middleware('permission:housekeeping.manage')
        ->name('hk.rooms.ooo.store');
    Route::delete('branches/{branch}/housekeeping/out-of-order/{out}', [HousekeepingTaskController::class, 'clearOutOfOrder'])
        ->middleware('permission:housekeeping.manage')
        ->name('hk.rooms.ooo.destroy');
    Route::post('branches/{branch}/housekeeping/rooms/{room}/minibar', [MinibarController::class, 'store'])
        ->middleware('role_or_permission:housekeeping.manage|housekeeping.assign')
        ->name('hk.minibar.store');
    Route::get('branches/{branch}/housekeeping/lost-found', [LostFoundController::class, 'index'])
        ->middleware('permission:housekeeping.view')
        ->name('hk.lost-found.index');
    Route::post('branches/{branch}/housekeeping/lost-found', [LostFoundController::class, 'store'])
        ->middleware('permission:housekeeping.manage')
        ->name('hk.lost-found.store');
    Route::post('branches/{branch}/housekeeping/lost-found/{item}/claim', [LostFoundController::class, 'claim'])
        ->middleware('permission:housekeeping.manage_lost_found')
        ->name('hk.lost-found.claim');
    Route::post('branches/{branch}/housekeeping/lost-found/{item}/dispose', [LostFoundController::class, 'dispose'])
        ->middleware('permission:housekeeping.manage_lost_found')
        ->name('hk.lost-found.dispose');

    // Business Date
    Route::get('branches/{branch}/business-date', [BusinessDateController::class, 'show'])
        ->middleware('permission:business_date.view')
        ->name('business-date.show');
    Route::post('branches/{branch}/business-date/advance', [BusinessDateController::class, 'advance'])
        ->middleware('permission:business_date.close')
        ->name('business-date.advance');

    // Idempotency inspector (read-only)
    Route::get('branches/{branch}/idempotency-keys', [IdempotencyController::class, 'index'])
        ->middleware('permission:idempotency.view')
        ->name('idempotency.index');

    // Financial journal (read-only, append-only)
    Route::get('branches/{branch}/journal', [JournalController::class, 'index'])
        ->middleware('permission:journal.view')
        ->name('journal.index');

    // Tax profiles
    Route::get('branches/{branch}/tax-profiles', [TaxProfileController::class, 'index'])
        ->middleware('permission:tax.view')
        ->name('tax-profiles.index');
    Route::post('branches/{branch}/tax-profiles', [TaxProfileController::class, 'store'])
        ->middleware('permission:tax.manage')
        ->name('tax-profiles.store');
    Route::post('branches/{branch}/tax-profiles/{profile}/deactivate', [TaxProfileController::class, 'deactivate'])
        ->middleware('permission:tax.manage')
        ->name('tax-profiles.deactivate');

    // Chart of accounts + trial balance
    Route::get('branches/{branch}/chart', [ChartAccountController::class, 'index'])
        ->middleware('permission:accounting.view')
        ->name('chart.index');
    Route::put('branches/{branch}/posting-rules/{rule}', [ChartAccountController::class, 'updateRule'])
        ->middleware('permission:accounting.manage_chart')
        ->name('posting-rules.update');
    Route::get('branches/{branch}/trial-balance', [TrialBalanceController::class, 'index'])
        ->middleware('permission:accounting.view')
        ->name('trial-balance.index');
    Route::post('branches/{branch}/trial-balance/close', [TrialBalanceController::class, 'close'])
        ->middleware('permission:accounting.close_period')
        ->name('trial-balance.close');

    // Accounting exports (external ledgers)
    Route::get('branches/{branch}/accounting', [AccountingExportController::class, 'index'])
        ->middleware('permission:accounting_export.view')
        ->name('accounting.index');
    Route::post('branches/{branch}/accounting/links', [AccountingExportController::class, 'storeLink'])
        ->middleware('permission:accounting_export.manage')
        ->name('accounting.links.store');
    Route::put('branches/{branch}/accounting/links/{link}', [AccountingExportController::class, 'storeMap'])
        ->middleware('permission:accounting_export.manage')
        ->name('accounting.links.map');
    Route::post('branches/{branch}/accounting/exports', [AccountingExportController::class, 'export'])
        ->middleware('permission:accounting_export.manage')
        ->name('accounting.exports.store');
    Route::post('branches/{branch}/accounting/exports/{export}/retry', [AccountingExportController::class, 'retry'])
        ->middleware('permission:accounting_export.retry')
        ->name('accounting.exports.retry');

    // Commissions (accrual inbox, payout builder, disputes)
    Route::get('branches/{branch}/commissions', [CommissionController::class, 'index'])
        ->middleware('permission:commissions.view')
        ->name('commissions.index');
    Route::post('branches/{branch}/commissions/rules', [CommissionController::class, 'storeRule'])
        ->middleware('permission:commissions.manage')
        ->name('commissions.rules.store');
    Route::post('branches/{branch}/commissions/payouts', [CommissionController::class, 'storePayout'])
        ->middleware('permission:commissions.manage')
        ->name('commissions.payouts.store');
    Route::post('branches/{branch}/commissions/payouts/{payout}/pay', [CommissionController::class, 'pay'])
        ->middleware('permission:commissions.pay')
        ->name('commissions.payouts.pay');
    Route::post('branches/{branch}/commissions/accruals/{accrual}/dispute', [CommissionController::class, 'dispute'])
        ->middleware('permission:commissions.manage')
        ->name('commissions.accruals.dispute');
    Route::post('branches/{branch}/commissions/accruals/{accrual}/resolve', [CommissionController::class, 'resolveDispute'])
        ->middleware('permission:commissions.manage')
        ->name('commissions.accruals.resolve');

    // Folio windows, routing, splits, master transfers
    Route::post('folios/{folio}/windows', [FolioWindowController::class, 'storeWindow'])
        ->middleware('permission:folios.manage')
        ->name('folio-windows.store');
    Route::post('folios/{folio}/routing-rules', [FolioWindowController::class, 'storeRule'])
        ->middleware('permission:folios.manage_routing')
        ->name('folio-routing.store');
    Route::post('transactions/{transaction}/split', [FolioWindowController::class, 'split'])
        ->middleware('permission:folios.split')
        ->name('transactions.split');
    Route::post('transactions/{transaction}/transfer-to-master', [FolioWindowController::class, 'transferToMaster'])
        ->middleware('permission:folios.transfer')
        ->name('transactions.transfer-master');

    // Cashier shifts + Z-report
    Route::get('branches/{branch}/cashier', [CashierShiftController::class, 'index'])
        ->middleware('permission:cashier.open_shift')
        ->name('cashier.index');
    Route::post('branches/{branch}/cashier/open', [CashierShiftController::class, 'open'])
        ->middleware('permission:cashier.open_shift')
        ->name('cashier.open');
    Route::post('cashier-shifts/{shift}/close', [CashierShiftController::class, 'close'])
        ->middleware('permission:cashier.close_shift')
        ->name('cashier.close');
    Route::get('branches/{branch}/cashier-shifts/{shift}/z-report', [CashierShiftController::class, 'zReport'])
        ->middleware('permission:reports.view')
        ->name('cashier.z-report');

    // Void / refund approvals
    Route::get('branches/{branch}/voids', [VoidRefundController::class, 'index'])
        ->middleware('permission:cashier.approve_void')
        ->name('voids.index');
    Route::post('transactions/{transaction}/request-void', [VoidRefundController::class, 'requestVoid'])
        ->middleware('permission:folios.manage')
        ->name('voids.request');
    Route::post('void-approvals/{approval}/approve', [VoidRefundController::class, 'approve'])
        ->middleware('permission:cashier.approve_void')
        ->name('voids.approve');
    Route::post('void-approvals/{approval}/reject', [VoidRefundController::class, 'reject'])
        ->middleware('permission:cashier.approve_void')
        ->name('voids.reject');
    Route::post('payment-transactions/{paymentTransaction}/refund', [VoidRefundController::class, 'refund'])
        ->middleware('permission:payments.refund')
        ->name('payments.refund');

    // Tape Chart
    Route::get('tape-chart', [TapeChartController::class, 'index'])
        ->middleware('permission:tape_chart.view')
        ->name('tape-chart.index');

    // Rooms
    Route::get('rooms', [RoomController::class, 'index'])
        ->middleware('permission:rooms.view')
        ->name('rooms.index');
    Route::post('rooms', [RoomController::class, 'store'])
        ->middleware('permission:rooms.manage')
        ->name('rooms.store');
    Route::put('rooms/{room}', [RoomController::class, 'update'])
        ->middleware('permission:rooms.manage')
        ->name('rooms.update');
    Route::patch('rooms/{room}/status', [RoomController::class, 'updateStatus'])
        ->middleware('permission:rooms.update_status')
        ->name('rooms.update-status');
    Route::delete('rooms/{room}', [RoomController::class, 'destroy'])
        ->middleware('permission:rooms.manage')
        ->name('rooms.destroy');

    // Cross-Branch Search (must be defined before the {reservation} wildcard)
    Route::get('reservations/cross-branch/search', [ReservationController::class, 'crossBranchSearch'])
        ->middleware('role:Global Admin|Property Owner')
        ->name('reservations.cross-branch');

    // Reservations
    Route::get('reservations', [ReservationController::class, 'index'])
        ->middleware('permission:reservations.view')
        ->name('reservations.index');
    Route::get('reservations/create', [ReservationController::class, 'create'])
        ->middleware('permission:reservations.create')
        ->name('reservations.create');
    Route::post('reservations', [ReservationController::class, 'store'])
        ->middleware('permission:reservations.create')
        ->name('reservations.store');
    Route::get('reservations/{reservation}', [ReservationController::class, 'show'])
        ->middleware('permission:reservations.view')
        ->name('reservations.show');
    Route::get('reservations/{reservation}/edit', [ReservationController::class, 'edit'])
        ->middleware('permission:reservations.update')
        ->name('reservations.edit');
    Route::put('reservations/{reservation}', [ReservationController::class, 'update'])
        ->middleware('permission:reservations.update')
        ->name('reservations.update');
    Route::post('reservations/{reservation}/check-in', [ReservationController::class, 'checkIn'])
        ->middleware(['permission:reservations.checkin', 'throttle:checkin'])
        ->name('reservations.check-in');
    Route::post('reservations/{reservation}/check-out', [ReservationController::class, 'checkOut'])
        ->middleware(['permission:reservations.checkout', 'throttle:checkin'])
        ->name('reservations.check-out');
    Route::post('reservations/{reservation}/cancel', [ReservationController::class, 'cancel'])
        ->middleware('permission:reservations.cancel')
        ->name('reservations.cancel');
    Route::post('reservations/{reservation}/move', [ReservationMoveController::class, 'store'])
        ->middleware('permission:reservations.move_room')
        ->name('reservations.move');
    Route::post('reservations/{reservation}/deposit', [ReservationController::class, 'collectDeposit'])
        ->middleware('permission:payments.charge')
        ->name('reservations.deposit');
    Route::delete('reservations/{reservation}', [ReservationController::class, 'destroy'])
        ->middleware('permission:reservations.delete')
        ->name('reservations.destroy');

    // Housekeeping
    Route::get('housekeeping', [HousekeepingController::class, 'index'])
        ->middleware('permission:housekeeping.view')
        ->name('housekeeping.index');
    Route::post('housekeeping', [HousekeepingController::class, 'store'])
        ->middleware('permission:housekeeping.manage')
        ->name('housekeeping.store');
    Route::put('housekeeping/{task}', [HousekeepingController::class, 'update'])
        ->middleware('permission:housekeeping.manage')
        ->name('housekeeping.update');
    Route::post('housekeeping/{task}/start', [HousekeepingController::class, 'start'])
        ->middleware('permission:housekeeping.manage')
        ->name('housekeeping.start');
    Route::post('housekeeping/{task}/complete', [HousekeepingController::class, 'complete'])
        ->middleware('permission:housekeeping.manage')
        ->name('housekeeping.complete');
    Route::delete('housekeeping/{task}', [HousekeepingController::class, 'destroy'])
        ->middleware('permission:housekeeping.manage')
        ->name('housekeeping.destroy');
    Route::get('housekeeping/mobile', [HousekeepingController::class, 'mobile'])
        ->middleware('permission:housekeeping.view')
        ->name('housekeeping.mobile');

    // Maintenance (ticket creation allowed with view so Front Desk can file tickets;
    // all other writes require manage; lock/unlock additionally requires door_lock.manage)
    Route::get('maintenance', [MaintenanceController::class, 'index'])
        ->middleware('permission:maintenance.view')
        ->name('maintenance.index');
    Route::get('maintenance/create', [MaintenanceController::class, 'create'])
        ->middleware('permission:maintenance.view')
        ->name('maintenance.create');
    Route::post('maintenance', [MaintenanceController::class, 'store'])
        ->middleware('permission:maintenance.view')
        ->name('maintenance.store');
    Route::get('maintenance/{ticket}', [MaintenanceController::class, 'show'])
        ->middleware('permission:maintenance.view')
        ->name('maintenance.show');
    Route::put('maintenance/{ticket}', [MaintenanceController::class, 'update'])
        ->middleware('permission:maintenance.manage')
        ->name('maintenance.update');
    Route::post('maintenance/{ticket}/start', [MaintenanceController::class, 'start'])
        ->middleware('permission:maintenance.manage')
        ->name('maintenance.start');
    Route::post('maintenance/{ticket}/complete', [MaintenanceController::class, 'complete'])
        ->middleware('permission:maintenance.manage')
        ->name('maintenance.complete');
    Route::post('maintenance/{ticket}/lock-room', [MaintenanceController::class, 'lockRoom'])
        ->middleware(['permission:maintenance.manage', 'permission:door_lock.manage'])
        ->name('maintenance.lock-room');
    Route::post('maintenance/{ticket}/unlock-room', [MaintenanceController::class, 'unlockRoom'])
        ->middleware(['permission:maintenance.manage', 'permission:door_lock.manage'])
        ->name('maintenance.unlock-room');
    Route::delete('maintenance/{ticket}', [MaintenanceController::class, 'destroy'])
        ->middleware('permission:maintenance.manage')
        ->name('maintenance.destroy');

    // Assets + SLA work orders
    Route::get('branches/{branch}/maintenance/assets', [AssetController::class, 'index'])
        ->middleware('permission:maintenance.view')
        ->name('assets.index');
    Route::post('branches/{branch}/maintenance/assets', [AssetController::class, 'store'])
        ->middleware('permission:maintenance.manage_assets')
        ->name('assets.store');
    Route::put('branches/{branch}/maintenance/assets/{asset}', [AssetController::class, 'update'])
        ->middleware('permission:maintenance.manage_assets')
        ->name('assets.update');
    Route::delete('branches/{branch}/maintenance/assets/{asset}', [AssetController::class, 'destroy'])
        ->middleware('permission:maintenance.manage_assets')
        ->name('assets.destroy');
    Route::post('branches/{branch}/maintenance/work-orders', [WorkOrderController::class, 'store'])
        ->middleware('permission:maintenance.manage')
        ->name('work-orders.store');
    Route::post('branches/{branch}/maintenance/work-orders/{ticket}/assign', [WorkOrderController::class, 'assign'])
        ->middleware('permission:maintenance.manage_sla')
        ->name('work-orders.assign');
    Route::post('branches/{branch}/maintenance/work-orders/{ticket}/resolve', [WorkOrderController::class, 'resolve'])
        ->middleware('permission:maintenance.manage')
        ->name('work-orders.resolve');
    Route::post('branches/{branch}/maintenance/work-orders/{ticket}/sla-check', [WorkOrderController::class, 'slaCheck'])
        ->middleware('permission:maintenance.manage_sla')
        ->name('work-orders.sla-check');

    // Folios
    Route::middleware('throttle:folio')->group(function () {
        Route::get('folios', [FolioController::class, 'index'])
            ->middleware('permission:folios.view')
            ->name('folios.index');
        Route::get('folios/search', [FolioController::class, 'search'])
            ->middleware('permission:folios.view')
            ->name('folios.search');
        Route::post('folios', [FolioController::class, 'store'])
            ->middleware('permission:folios.manage')
            ->name('folios.store');
        Route::get('folios/{folio}', [FolioController::class, 'show'])
            ->middleware('permission:folios.view')
            ->name('folios.show');
        Route::get('folios/{folio}/bill', [FolioController::class, 'bill'])
            ->middleware('permission:folios.view')
            ->name('folios.bill');
        Route::get('folios/{folio}/bill/pdf', [FolioController::class, 'billPdf'])
            ->middleware('permission:folios.view')
            ->name('folios.bill-pdf');
        Route::post('folios/{folio}/charges', [FolioController::class, 'postCharge'])
            ->middleware('permission:folios.manage')
            ->name('folios.post-charge');
        Route::post('folios/{folio}/payments', [FolioController::class, 'recordPayment'])
            ->middleware('permission:folios.manage')
            ->name('folios.record-payment');
        Route::post('folios/{folio}/checkout', [FolioController::class, 'checkout'])
            ->middleware('permission:folios.manage')
            ->name('folios.checkout');
        Route::post('folios/{folio}/child', [FolioController::class, 'createChild'])
            ->middleware('permission:folios.manage')
            ->name('folios.create-child');
        Route::post('folios/transactions/{transaction}/transfer', [FolioController::class, 'transferTransaction'])
            ->middleware('permission:folios.manage')
            ->name('folios.transfer');
        Route::post('folios/{folio}/disputes', [FolioDisputeController::class, 'store'])
            ->middleware('permission:folios.manage')
            ->name('folios.disputes.store');
        Route::put('folios/disputes/{dispute}', [FolioDisputeController::class, 'update'])
            ->middleware('permission:folios.manage')
            ->name('folios.disputes.update');
    });

    // Analytics
    Route::get('analytics', [AnalyticsController::class, 'index'])
        ->middleware('permission:analytics.view')
        ->name('analytics.index');

    // Warehouse feed (R2 snapshots + manifests)
    Route::get('analytics/warehouse', [WarehouseController::class, 'index'])
        ->middleware('permission:analytics.view')
        ->name('warehouse.index');
    Route::post('analytics/warehouse/exports', [WarehouseController::class, 'export'])
        ->middleware('permission:analytics.export_warehouse')
        ->name('warehouse.exports.store');
    Route::post('analytics/warehouse/manifests/{manifest}/verify', [WarehouseController::class, 'verify'])
        ->middleware('permission:analytics.export_warehouse')
        ->name('warehouse.manifests.verify');

    // Revenue analytics (ADR/RevPAR/pace/OTB vs budget)
    Route::get('branches/{branch}/revenue', [RevenueReportController::class, 'index'])
        ->middleware('permission:analytics.view')
        ->name('revenue.index');
    Route::post('branches/{branch}/revenue/budgets', [RevenueReportController::class, 'storeBudget'])
        ->middleware('permission:analytics.manage')
        ->name('revenue.budgets.store');

    // Reports
    Route::get('reports', [ReportController::class, 'index'])
        ->middleware('permission:reports.view')
        ->name('reports.index');
    Route::get('reports/night-audit/export', [ReportController::class, 'nightAuditExport'])
        ->middleware('permission:reports.export')
        ->name('reports.night-audit.export');
    Route::get('reports/financial/export', [ReportController::class, 'financialExport'])
        ->middleware('permission:reports.export')
        ->name('reports.financial.export');

    // Yield Rules
    Route::get('yield-rules', [YieldRuleController::class, 'index'])
        ->middleware('permission:yield_rules.view')
        ->name('yield-rules.index');
    Route::post('yield-rules', [YieldRuleController::class, 'store'])
        ->middleware('permission:yield_rules.manage')
        ->name('yield-rules.store');
    Route::put('yield-rules/{yieldRule}', [YieldRuleController::class, 'update'])
        ->middleware('permission:yield_rules.manage')
        ->name('yield-rules.update');
    Route::delete('yield-rules/{yieldRule}', [YieldRuleController::class, 'destroy'])
        ->middleware('permission:yield_rules.manage')
        ->name('yield-rules.destroy');

    // Rate Overrides
    Route::get('rate-overrides', [RateOverrideController::class, 'index'])
        ->middleware('permission:rate_overrides.view')
        ->name('rate-overrides.index');
    Route::post('rate-overrides', [RateOverrideController::class, 'store'])
        ->middleware('permission:rate_overrides.manage')
        ->name('rate-overrides.store');
    Route::put('rate-overrides/{rateOverride}', [RateOverrideController::class, 'update'])
        ->middleware('permission:rate_overrides.manage')
        ->name('rate-overrides.update');
    Route::delete('rate-overrides/{rateOverride}', [RateOverrideController::class, 'destroy'])
        ->middleware('permission:rate_overrides.manage')
        ->name('rate-overrides.destroy');

    // Menu Items
    Route::get('menu-items', [MenuItemController::class, 'index'])
        ->middleware('permission:menu_items.view')
        ->name('menu-items.index');
    Route::post('menu-items', [MenuItemController::class, 'store'])
        ->middleware('permission:menu_items.manage')
        ->name('menu-items.store');
    Route::put('menu-items/{menuItem}', [MenuItemController::class, 'update'])
        ->middleware('permission:menu_items.manage')
        ->name('menu-items.update');
    Route::delete('menu-items/{menuItem}', [MenuItemController::class, 'destroy'])
        ->middleware('permission:menu_items.manage')
        ->name('menu-items.destroy');

    // Rate Plans
    Route::get('rate-plans', [RatePlanController::class, 'index'])
        ->middleware('permission:rate_plans.view')
        ->name('rate-plans.index');
    Route::post('rate-plans', [RatePlanController::class, 'store'])
        ->middleware('permission:rate_plans.manage')
        ->name('rate-plans.store');
    Route::put('rate-plans/{ratePlan}', [RatePlanController::class, 'update'])
        ->middleware('permission:rate_plans.manage')
        ->name('rate-plans.update');
    Route::delete('rate-plans/{ratePlan}', [RatePlanController::class, 'destroy'])
        ->middleware('permission:rate_plans.manage')
        ->name('rate-plans.destroy');

    // Outlets
    Route::get('outlets', [OutletController::class, 'index'])
        ->middleware('permission:outlets.view')
        ->name('outlets.index');
    Route::post('outlets', [OutletController::class, 'store'])
        ->middleware('permission:outlets.manage')
        ->name('outlets.store');
    Route::put('outlets/{outlet}', [OutletController::class, 'update'])
        ->middleware('permission:outlets.manage')
        ->name('outlets.update');
    Route::delete('outlets/{outlet}', [OutletController::class, 'destroy'])
        ->middleware('permission:outlets.manage')
        ->name('outlets.destroy');

    // Inventory
    Route::get('inventory', [InventoryController::class, 'index'])
        ->middleware('permission:inventory.view')
        ->name('inventory.index');
    Route::post('inventory', [InventoryController::class, 'store'])
        ->middleware('permission:inventory.manage')
        ->name('inventory.store');
    Route::put('inventory/{inventoryItem}', [InventoryController::class, 'update'])
        ->middleware('permission:inventory.manage')
        ->name('inventory.update');
    Route::post('inventory/{inventoryItem}/restock', [InventoryController::class, 'restock'])
        ->middleware('permission:inventory.manage')
        ->name('inventory.restock');
    Route::delete('inventory/{inventoryItem}', [InventoryController::class, 'destroy'])
        ->middleware('permission:inventory.manage')
        ->name('inventory.destroy');

    // F&B costing (suppliers, POs, GRNs)
    Route::get('branches/{branch}/costing', [PurchaseOrderController::class, 'index'])
        ->middleware('permission:inventory.view_costing')
        ->name('costing.index');
    Route::post('branches/{branch}/suppliers', [SupplierController::class, 'store'])
        ->middleware('permission:inventory.manage_suppliers')
        ->name('suppliers.store');
    Route::delete('branches/{branch}/suppliers/{supplier}', [SupplierController::class, 'destroy'])
        ->middleware('permission:inventory.manage_suppliers')
        ->name('suppliers.destroy');
    Route::post('branches/{branch}/purchase-orders', [PurchaseOrderController::class, 'store'])
        ->middleware('permission:inventory.manage_po_grn')
        ->name('purchase-orders.store');
    Route::post('branches/{branch}/purchase-orders/{order}/send', [PurchaseOrderController::class, 'send'])
        ->middleware('permission:inventory.manage_po_grn')
        ->name('purchase-orders.send');
    Route::post('branches/{branch}/purchase-orders/{order}/cancel', [PurchaseOrderController::class, 'cancel'])
        ->middleware('permission:inventory.manage_po_grn')
        ->name('purchase-orders.cancel');
    Route::post('branches/{branch}/purchase-orders/{order}/receive', [GoodsReceiptController::class, 'store'])
        ->middleware('permission:inventory.manage_po_grn')
        ->name('goods-receipts.store');
    Route::get('branches/{branch}/costing/variance', [PurchaseOrderController::class, 'variance'])
        ->middleware('permission:inventory.view_costing')
        ->name('costing.variance');

    // Transfers
    Route::get('transfers', [TransferController::class, 'index'])
        ->middleware('permission:transfers.view')
        ->name('transfers.index');
    Route::post('transfers', [TransferController::class, 'store'])
        ->middleware('permission:transfers.manage')
        ->name('transfers.store');
    Route::get('transfers/{transferRequest}', [TransferController::class, 'show'])
        ->middleware('permission:transfers.view')
        ->name('transfers.show');
    Route::post('transfers/{transferRequest}/approve', [TransferController::class, 'approve'])
        ->middleware('permission:transfers.manage')
        ->name('transfers.approve');
    Route::post('transfers/{transferRequest}/ship', [TransferController::class, 'ship'])
        ->middleware('permission:transfers.manage')
        ->name('transfers.ship');
    Route::post('transfers/{transferRequest}/receive', [TransferController::class, 'receive'])
        ->middleware('permission:transfers.manage')
        ->name('transfers.receive');

    // Bank Profiles
    Route::get('bank-profiles', [BankProfileController::class, 'index'])
        ->middleware('permission:settings.manage')
        ->name('bank-profiles.index');
    Route::post('bank-profiles', [BankProfileController::class, 'store'])
        ->middleware('permission:settings.manage')
        ->name('bank-profiles.store');
    Route::put('bank-profiles/{bankProfile}', [BankProfileController::class, 'update'])
        ->middleware('permission:settings.manage')
        ->name('bank-profiles.update');
    Route::delete('bank-profiles/{bankProfile}', [BankProfileController::class, 'destroy'])
        ->middleware('permission:settings.manage')
        ->name('bank-profiles.destroy');

    // Group Ledgers
    Route::get('group-ledgers', [GroupLedgerController::class, 'index'])
        ->middleware('permission:analytics.view')
        ->name('group-ledger.index');
    Route::get('group-ledgers/{groupLedger}', [GroupLedgerController::class, 'show'])
        ->middleware('permission:analytics.view')
        ->name('group-ledger.show');
    Route::post('group-ledgers', [GroupLedgerController::class, 'store'])
        ->middleware('permission:analytics.manage')
        ->name('group-ledger.store');
    Route::put('group-ledgers/{groupLedger}', [GroupLedgerController::class, 'update'])
        ->middleware('permission:analytics.manage')
        ->name('group-ledger.update');
    Route::delete('group-ledgers/{groupLedger}', [GroupLedgerController::class, 'destroy'])
        ->middleware('permission:analytics.manage')
        ->name('group-ledger.destroy');

    // GDPR
    Route::post('admin/guest/anonymize', [GdprController::class, 'anonymize'])
        ->middleware('permission:guests.manage')
        ->name('admin.guest.anonymize');
    Route::get('admin/guest/export', [GdprController::class, 'export'])
        ->middleware('permission:guests.view')
        ->name('admin.guest.export');

    // KDS
    Route::get('kds', [KdsController::class, 'index'])
        ->middleware('permission:kds.view')
        ->name('kds.index');
    Route::patch('kds/items/{kotItem}/status', [KdsController::class, 'updateStatus'])
        ->middleware('permission:kds.manage')
        ->name('kds.update-status');
    Route::post('kds/menu-items/{menuItem}/toggle-stock', [KdsController::class, 'toggleStock'])
        ->middleware('permission:kds.manage')
        ->name('kds.toggle-stock');

    // Kitchen Waste
    Route::get('kitchen/waste', [KitchenWasteController::class, 'index'])
        ->middleware('permission:kds.view')
        ->name('kitchen.waste.index');
    Route::post('kitchen/waste', [KitchenWasteController::class, 'store'])
        ->middleware('permission:kds.manage')
        ->name('kitchen.waste.store');
    Route::get('kitchen/waste/report', [KitchenWasteController::class, 'report'])
        ->middleware('permission:kds.view')
        ->name('kitchen.waste.report');

    // POS Terminal
    Route::get('pos', [PosTerminalController::class, 'index'])
        ->middleware('permission:pos.view')
        ->name('pos.index');
    Route::get('pos/{outlet}', [PosTerminalController::class, 'terminal'])
        ->middleware('permission:pos.view')
        ->name('pos.terminal');
    Route::post('pos/{outlet}/charge', [PosTerminalController::class, 'charge'])
        ->middleware('permission:pos.manage')
        ->name('pos.charge');

    // POS completeness (floor, tabs, splits, offline replay)
    Route::get('pos/{outlet}/floor', [PosTabController::class, 'index'])
        ->middleware('permission:pos.view')
        ->name('pos.floor');
    Route::post('pos/{outlet}/tabs', [PosTabController::class, 'open'])
        ->middleware('permission:pos.manage')
        ->name('pos.tabs.open');
    Route::post('pos/tabs/{charge}/items', [PosTabController::class, 'addItems'])
        ->middleware('permission:pos.manage')
        ->name('pos.tabs.items');
    Route::post('pos/tabs/{charge}/fire', [PosTabController::class, 'fire'])
        ->middleware('permission:pos.manage')
        ->name('pos.tabs.fire');
    Route::post('pos/tabs/{charge}/split', [PosTabController::class, 'split'])
        ->middleware('permission:pos.manage')
        ->name('pos.tabs.split');
    Route::post('pos/tabs/{charge}/post', [PosTabController::class, 'post'])
        ->middleware('permission:pos.manage')
        ->name('pos.tabs.post');
    Route::post('pos/{outlet}/offline-replay', [PosTabController::class, 'replayOffline'])
        ->middleware('permission:pos.manage')
        ->name('pos.offline.replay');
    Route::post('pos/{outlet}/tables', [DiningTableController::class, 'store'])
        ->middleware('permission:pos.manage_floor')
        ->name('pos.tables.store');
    Route::delete('pos/{outlet}/tables/{table}', [DiningTableController::class, 'destroy'])
        ->middleware('permission:pos.manage_floor')
        ->name('pos.tables.destroy');
    Route::post('pos/{outlet}/happy-hours', [HappyHourController::class, 'store'])
        ->middleware('permission:pos.manage_pricing')
        ->name('pos.happy-hours.store');
    Route::delete('pos/{outlet}/happy-hours/{happyHour}', [HappyHourController::class, 'destroy'])
        ->middleware('permission:pos.manage_pricing')
        ->name('pos.happy-hours.destroy');
    Route::post('branches/{branch}/modifiers', [PosModifierController::class, 'store'])
        ->middleware('permission:pos.manage_pricing')
        ->name('pos.modifiers.store');
    Route::delete('branches/{branch}/modifiers/{modifier}', [PosModifierController::class, 'destroy'])
        ->middleware('permission:pos.manage_pricing')
        ->name('pos.modifiers.destroy');

    // Laundry
    Route::get('laundry', [LaundryController::class, 'index'])
        ->middleware('permission:laundry.view')
        ->name('laundry.index');
    Route::get('laundry/{laundryOrder}', [LaundryController::class, 'show'])
        ->middleware('permission:laundry.view')
        ->name('laundry.show');
    Route::post('laundry/{laundryOrder}/pickup', [LaundryController::class, 'pickup'])
        ->middleware('permission:laundry.manage')
        ->name('laundry.pickup');
    Route::post('laundry/{laundryOrder}/deliver', [LaundryController::class, 'deliver'])
        ->middleware('permission:laundry.manage')
        ->name('laundry.deliver');
    Route::post('laundry/{laundryOrder}/verify', [LaundryController::class, 'verify'])
        ->middleware('permission:laundry.manage')
        ->name('laundry.verify');

    // Channels
    Route::get('channels', [ChannelManagerController::class, 'index'])
        ->middleware('permission:channels.view')
        ->name('channels.index');
    Route::post('channels/{channelProvider}/sync', [ChannelManagerController::class, 'sync'])
        ->middleware('permission:channels.manage')
        ->name('channels.sync');
    Route::post('channels/{channelProvider}/pull', [ChannelManagerController::class, 'pullReservations'])
        ->middleware('permission:channels.manage')
        ->name('channels.pull');

    // Channel reliability (outbox, mappings, reconciliation)
    Route::get('channels/reliability', [ChannelMessageController::class, 'index'])
        ->middleware('permission:channels.view')
        ->name('channels.reliability');
    Route::post('channels/messages', [ChannelMessageController::class, 'push'])
        ->middleware('permission:channels.manage')
        ->name('channels.messages.push');
    Route::post('channels/messages/{message}/replay', [ChannelMessageController::class, 'replay'])
        ->middleware('permission:channels.replay')
        ->name('channels.messages.replay');
    Route::post('channels/mappings', [ChannelMappingController::class, 'store'])
        ->middleware('permission:channels.manage_mapping')
        ->name('channels.mappings.store');
    Route::delete('channels/mappings/{mapping}', [ChannelMappingController::class, 'destroy'])
        ->middleware('permission:channels.manage_mapping')
        ->name('channels.mappings.destroy');

    // CRS
    Route::get('crs', [CrsController::class, 'index'])
        ->middleware('permission:crs.view')
        ->name('crs.index');

    // Developers: API consumers, scopes, webhook secrets + delivery log
    Route::get('developers', [DevelopersController::class, 'index'])
        ->middleware('permission:api.view')
        ->name('developers.index');
    Route::post('developers/consumers', [DevelopersController::class, 'store'])
        ->middleware('permission:api.manage_consumers')
        ->name('developers.store');
    Route::post('developers/consumers/{consumer}/tokens', [DevelopersController::class, 'issueToken'])
        ->middleware('permission:api.manage_consumers')
        ->name('developers.tokens.store');
    Route::post('developers/consumers/{consumer}/rotate', [DevelopersController::class, 'rotate'])
        ->middleware('permission:api.manage_consumers')
        ->name('developers.rotate');
    Route::post('developers/consumers/{consumer}/toggle', [DevelopersController::class, 'toggle'])
        ->middleware('permission:api.manage_consumers')
        ->name('developers.toggle');
    Route::post('developers/deliveries/{delivery}/replay', [DevelopersController::class, 'replay'])
        ->middleware('permission:api.manage_consumers')
        ->name('developers.deliveries.replay');
    Route::post('crs', [CrsController::class, 'store'])
        ->middleware('permission:crs.manage')
        ->name('crs.store');

    // Audit Flags
    Route::get('audit/flags', [AuditFlagController::class, 'index'])
        ->middleware('permission:audit.view')
        ->name('audit.flags.index');
    Route::get('audit/flags/{auditFlag}', [AuditFlagController::class, 'show'])
        ->middleware('permission:audit.view')
        ->name('audit.flags.show');
    Route::post('audit/flags/{auditFlag}/review', [AuditFlagController::class, 'review'])
        ->middleware('permission:audit.manage')
        ->name('audit.flags.review');
    Route::post('audit/flags/{auditFlag}/suppress', [AuditFlagController::class, 'suppress'])
        ->middleware('permission:audit.manage')
        ->name('audit.flags.suppress');

    // City Ledger
    Route::get('city-ledger', [CityLedgerController::class, 'index'])
        ->middleware('permission:city_ledger.view')
        ->name('city-ledger.index');
    Route::post('city-ledger', [CityLedgerController::class, 'store'])
        ->middleware('permission:city_ledger.manage')
        ->name('city-ledger.store');
    Route::get('city-ledger/{cityLedgerAccount}', [CityLedgerController::class, 'show'])
        ->middleware('permission:city_ledger.view')
        ->name('city-ledger.show');
    Route::put('city-ledger/{cityLedgerAccount}', [CityLedgerController::class, 'update'])
        ->middleware('permission:city_ledger.manage')
        ->name('city-ledger.update');
    Route::post('city-ledger/{cityLedgerAccount}/charge', [CityLedgerController::class, 'charge'])
        ->middleware('permission:city_ledger.manage')
        ->name('city-ledger.charge');
    Route::post('city-ledger/{cityLedgerAccount}/pay', [CityLedgerController::class, 'pay'])
        ->middleware('permission:city_ledger.manage')
        ->name('city-ledger.pay');
    Route::get('city-ledger/{cityLedgerAccount}/statement', [CityLedgerController::class, 'statement'])
        ->middleware('permission:city_ledger.view')
        ->name('city-ledger.statement');

    // Registration Cards
    Route::get('reservations/{reservation}/registration-card', [RegistrationCardController::class, 'show'])
        ->middleware('permission:reservations.view')
        ->name('reservations.registration-card.show');
    Route::post('reservations/{reservation}/registration-card', [RegistrationCardController::class, 'store'])
        ->middleware('permission:reservations.update')
        ->name('reservations.registration-card.store');

    // Privacy (DSAR + retention)
    Route::get('branches/{branch}/privacy/dsar', [DsarController::class, 'index'])
        ->middleware('permission:privacy.view')
        ->name('dsar.index');
    Route::post('branches/{branch}/privacy/dsar', [DsarController::class, 'store'])
        ->middleware('permission:privacy.view')
        ->name('dsar.store');
    Route::post('branches/{branch}/privacy/dsar/{dsar}/fulfill', [DsarController::class, 'fulfill'])
        ->middleware('permission:privacy.fulfill')
        ->name('dsar.fulfill');
    Route::post('branches/{branch}/privacy/dsar/{dsar}/reject', [DsarController::class, 'reject'])
        ->middleware('permission:privacy.fulfill')
        ->name('dsar.reject');
    Route::get('branches/{branch}/privacy/dsar/{dsar}/download', [DsarController::class, 'download'])
        ->middleware('permission:privacy.fulfill')
        ->name('dsar.download');
    Route::post('branches/{branch}/privacy/retention', [RetentionPolicyController::class, 'store'])
        ->middleware('permission:privacy.set_retention')
        ->name('retention.store');

    // Upsells
    Route::get('branches/{branch}/upsells', [UpsellOfferController::class, 'index'])
        ->middleware('permission:upsell.view')
        ->name('upsells.index');
    Route::post('branches/{branch}/upsells', [UpsellOfferController::class, 'store'])
        ->middleware('permission:upsell.manage')
        ->name('upsells.store');
    Route::put('branches/{branch}/upsells/{offer}', [UpsellOfferController::class, 'update'])
        ->middleware('permission:upsell.manage')
        ->name('upsells.update');
    Route::get('reservations/{reservation}/upsells/quote', [UpsellAcceptanceController::class, 'quote'])
        ->middleware('permission:upsell.view')
        ->name('upsells.quote');
    Route::post('reservations/{reservation}/upsells/accept', [UpsellAcceptanceController::class, 'accept'])
        ->middleware('permission:upsell.view')
        ->name('upsells.accept');

    // CRM: loyalty, consent, surveys
    Route::get('branches/{branch}/crm', [LoyaltyController::class, 'index'])
        ->middleware('permission:crm.view')
        ->name('crm.index');
    Route::post('branches/{branch}/crm/enroll', [LoyaltyController::class, 'enroll'])
        ->middleware('permission:crm.manage')
        ->name('crm.enroll');
    Route::post('branches/{branch}/crm/redeem', [LoyaltyController::class, 'redeem'])
        ->middleware('permission:crm.redeem')
        ->name('crm.redeem');
    Route::post('guests/{guest}/consents', [ConsentController::class, 'store'])
        ->middleware('permission:crm.manage')
        ->name('consents.store');

    // Statutory reporting
    Route::get('branches/{branch}/compliance/statutory', [StatutoryReportController::class, 'index'])
        ->middleware('permission:statutory.view')
        ->name('statutory.index');
    Route::post('branches/{branch}/compliance/statutory', [StatutoryReportController::class, 'store'])
        ->middleware('permission:statutory.generate')
        ->name('statutory.store');
    Route::get('branches/{branch}/compliance/statutory/{report}/download', [StatutoryReportController::class, 'download'])
        ->middleware('permission:statutory.view')
        ->name('statutory.download');
    Route::post('branches/{branch}/compliance/statutory/{report}/resubmit', [StatutoryReportController::class, 'resubmit'])
        ->middleware('permission:statutory.generate')
        ->name('statutory.resubmit');

    // Guest profiles, merges, DNR, identity
    Route::get('guests/{guest}', [GuestProfileController::class, 'show'])
        ->middleware('permission:guests.view')
        ->name('guests.profile');
    Route::get('branches/{branch}/guests/merges', [GuestMergeController::class, 'index'])
        ->middleware('permission:guests.merge')
        ->name('guests.merges.index');
    Route::post('guests/merges', [GuestMergeController::class, 'store'])
        ->middleware('permission:guests.merge')
        ->name('guests.merges.store');
    Route::get('guests/{guest}/merge-suggestions', [GuestMergeController::class, 'suggest'])
        ->middleware('permission:guests.merge')
        ->name('guests.merges.suggest');
    Route::get('branches/{branch}/dnr', [DoNotRentController::class, 'index'])
        ->middleware('permission:guests.manage_dnr')
        ->name('dnr.index');
    Route::post('branches/{branch}/dnr', [DoNotRentController::class, 'store'])
        ->middleware('permission:guests.manage_dnr')
        ->name('dnr.store');
    Route::delete('branches/{branch}/dnr/{entry}', [DoNotRentController::class, 'destroy'])
        ->middleware('permission:guests.manage_dnr')
        ->name('dnr.destroy');
    Route::post('guests/{guest}/identity-documents', [IdentityDocumentController::class, 'store'])
        ->middleware('permission:guests.update')
        ->name('identity.store');
    Route::get('identity-documents/{document}/download', [IdentityDocumentController::class, 'download'])
        ->middleware('permission:guests.view')
        ->name('identity.download');
    Route::get('identity-documents/{document}/reveal', [IdentityDocumentController::class, 'reveal'])
        ->middleware('permission:guests.view')
        ->name('identity.reveal');

    // Admin — Branch Wizard
    Route::get('admin/branches/create', [BranchWizardController::class, 'index'])
        ->middleware('permission:branches.manage')
        ->name('admin.branches.create');
    Route::post('admin/branches', [BranchWizardController::class, 'store'])
        ->middleware('permission:branches.manage')
        ->name('admin.branches.store');

    // Admin — Data Import
    Route::get('admin/import/rooms', [ImportController::class, 'roomsForm'])
        ->middleware('permission:branches.manage')
        ->name('admin.import.rooms');
    Route::post('admin/import/rooms', [ImportController::class, 'roomsImport'])
        ->middleware('permission:branches.manage')
        ->name('admin.import.rooms.post');
    Route::get('admin/import/guests', [ImportController::class, 'guestsForm'])
        ->middleware('permission:branches.manage')
        ->name('admin.import.guests');
    Route::post('admin/import/guests', [ImportController::class, 'guestsImport'])
        ->middleware('permission:branches.manage')
        ->name('admin.import.guests.post');
    Route::get('admin/import/reservations', [ImportController::class, 'reservationsForm'])
        ->middleware('permission:branches.manage')
        ->name('admin.import.reservations');
    Route::post('admin/import/reservations', [ImportController::class, 'reservationsImport'])
        ->middleware('permission:branches.manage')
        ->name('admin.import.reservations.post');
    Route::get('admin/import/template/{type}', [ImportTemplateController::class, 'download'])
        ->middleware('permission:branches.manage')
        ->name('admin.import.template');

    // Admin — Roles & Permissions
    Route::get('admin/roles', [RoleController::class, 'index'])
        ->middleware('permission:roles.view')
        ->name('admin.roles.index');
    Route::post('admin/roles', [RoleController::class, 'store'])
        ->middleware('permission:roles.assign')
        ->name('admin.roles.store');
    Route::put('admin/roles/{role}', [RoleController::class, 'update'])
        ->middleware('permission:roles.assign')
        ->name('admin.roles.update');
    Route::delete('admin/roles/{role}', [RoleController::class, 'destroy'])
        ->middleware('permission:roles.assign')
        ->name('admin.roles.destroy');

    // Admin — Users
    Route::get('admin/users', [UserController::class, 'index'])
        ->middleware('permission:users.view')
        ->name('admin.users.index');
    Route::post('admin/users', [UserController::class, 'store'])
        ->middleware('permission:users.create')
        ->name('admin.users.store');
    Route::put('admin/users/{user}', [UserController::class, 'update'])
        ->middleware('permission:users.update')
        ->name('admin.users.update');
    Route::delete('admin/users/{user}', [UserController::class, 'destroy'])
        ->middleware('permission:users.delete')
        ->name('admin.users.destroy');

    // Admin — System health
    Route::get('admin/system-health', [SystemController::class, 'health'])
        ->middleware('permission:system_health.view')
        ->name('admin.system-health');

    // Night audit (idempotent, resumable)
    Route::get('branches/{branch}/night-audit', [NightAuditController::class, 'index'])
        ->middleware('permission:audit.view')
        ->name('night-audit.index');
    Route::post('branches/{branch}/night-audit/run', [NightAuditController::class, 'run'])
        ->middleware('permission:audit.run_night_audit')
        ->name('night-audit.run');
    Route::post('branches/{branch}/night-audit/{run}/retry', [NightAuditController::class, 'retry'])
        ->middleware('permission:audit.retry_night_audit')
        ->name('night-audit.retry');

    // Tablet Session Management
    Route::post('tablet/pair', [TabletSessionController::class, 'pair'])
        ->middleware('permission:rooms.manage')
        ->name('tablet.pair');
    Route::post('tablet/unpair', [TabletSessionController::class, 'unpair'])
        ->middleware('permission:rooms.manage')
        ->name('tablet.unpair');
    Route::post('tablet/wipe', [TabletSessionController::class, 'wipe'])
        ->middleware('permission:rooms.manage')
        ->name('tablet.wipe');

    // Tablet Orders
    Route::post('tablet/orders', [TabletOrderController::class, 'store'])
        ->middleware('permission:rooms.manage')
        ->name('tablet.orders.store');
    Route::get('tablet/orders/{tabletOrder}', [TabletOrderController::class, 'show'])
        ->middleware('permission:rooms.view')
        ->name('tablet.order.show');
    Route::get('tablet/orders/{tabletOrder}/track', [TabletOrderController::class, 'track'])
        ->middleware('permission:rooms.view')
        ->name('tablet.order.track');
    Route::post('tablet/orders/{tabletOrder}/cancel', [TabletOrderController::class, 'cancel'])
        ->middleware('permission:rooms.manage')
        ->name('tablet.orders.cancel');

    // Reports
    Route::get('reports/pnl', [ReportController::class, 'profitAndLoss'])
        ->middleware('permission:reports.view')
        ->name('reports.pnl');
    Route::get('reports/channel-yield', [ReportController::class, 'channelYield'])
        ->middleware('permission:reports.view')
        ->name('reports.channel-yield');
    Route::get('reports/tax-liability', [ReportController::class, 'taxLiability'])
        ->middleware('permission:reports.view')
        ->name('reports.tax-liability');
});

// Public Tablet Routes (no auth - room-bound by confirmation)
Route::get('tablet/{confirmationNumber}/menu', [TabletMenuController::class, 'index'])->name('tablet.menu');

require __DIR__.'/settings.php';
