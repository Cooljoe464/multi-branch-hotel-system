<?php

use App\Models\Branch;
use App\Models\BusinessDate;
use App\Models\CashierShift;
use App\Models\VoidRefundApproval;
use App\Models\VoidRefundCode;
use App\Services\BusinessDateService;
use App\Services\CashierShiftService;
use App\Services\FolioService;
use App\Services\VoidRefundService;
use Database\Seeders\ChartSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->branch = Branch::factory()->create();
    $this->user = $this->makeAdminUser($this->branch);
    $this->seed(ChartSeeder::class);
    app(BusinessDateService::class)->current($this->branch);
    (new VoidRefundService)->seedCodes($this->branch->id);
    $this->shifts = app(CashierShiftService::class);
});

it('opens one shift per cashier and closes with exact drawer math', function () {
    $today = BusinessDate::forBranch($this->branch->id)->open()->firstOrFail()->business_date->toDateString();

    $shift = $this->shifts->open($this->branch, $this->user, 10000, $today);

    expect($shift->isOpen())->toBeTrue();

    // Second open attempt fails.
    expect(fn () => $this->shifts->open($this->branch, $this->user, 5000, $today))
        ->toThrow(LogicException::class, 'already has an open shift');

    $folio = (new FolioService)->createStaffFolio($this->branch->id, 'Drawer Guest');
    (new FolioService)->recordPayment($folio, 25000, 'cash', $this->user->id);
    (new FolioService)->recordPayment($folio, 15000, 'card', $this->user->id);

    // Expected = float 10,000 + cash 25,000 (card excluded).
    $closed = $this->shifts->close($shift, 35000);

    expect($closed->expected_cash_minor)->toBe(35000)
        ->and($closed->counted_cash_minor)->toBe(35000)
        ->and($closed->variance_minor)->toBe(0)
        ->and($closed->status)->toBe(CashierShift::STATUS_CLOSED);

    // Re-closing is rejected; a new shift opens after close.
    expect(fn () => $this->shifts->close($shift->fresh(), 35000))->toThrow(LogicException::class);
    $next = $this->shifts->open($this->branch, $this->user, 5000, $today);
    expect($next->isOpen())->toBeTrue();
});

it('requires a note for over-threshold variance and flags it', function () {
    $today = BusinessDate::forBranch($this->branch->id)->open()->firstOrFail()->business_date->toDateString();
    $shift = $this->shifts->open($this->branch, $this->user, 10000, $today);

    $folio = (new FolioService)->createStaffFolio($this->branch->id, 'Short Guest');
    (new FolioService)->recordPayment($folio, 20000, 'cash', $this->user->id);

    // Expected 30,000, counted 25,000: variance -5,000 over the 1,000 default.
    expect(fn () => $this->shifts->close($shift, 25000))->toThrow(LogicException::class, 'note is required');

    $closed = $this->shifts->close($shift, 25000, 'Drawer came up short at count.');

    expect($closed->variance_minor)->toBe(-5000);

    $report = $this->shifts->zReport($shift->fresh());
    expect($report['expected_cash_minor'])->toBe(30000);
});

it('gates voids behind supervisor approval with segregation', function () {
    $folio = (new FolioService)->createStaffFolio($this->branch->id, 'Void Guest');
    $tx = (new FolioService)->postManualCharge($folio, 'misc', 'Charge', 9000, null, $this->user->id);

    $code = VoidRefundCode::where('branch_id', $this->branch->id)->where('code', 'GUEST_COMPLAINT')->firstOrFail();

    // Front-desk cashier requests: protected code stays pending.
    $cashier = $this->makeAdminUser($this->branch);
    $cashier->removeRole('Global Admin');
    $cashier->assignRole('Front Desk');

    $approval = (new VoidRefundService)->requestVoid($tx, $code, $cashier, 'Guest unhappy.');
    expect($approval->status)->toBe(VoidRefundApproval::STATUS_PENDING);
    expect($tx->fresh()->is_voided)->toBeFalse();

    // Self-approval is rejected.
    expect(fn () => (new VoidRefundService)->approve($approval, $cashier))
        ->toThrow(LogicException::class, 'different user');

    // Branch GM approves: void executes once with the reason stamped.
    $gm = $this->makeAdminUser($this->branch);
    $gm->removeRole('Global Admin');
    $gm->assignRole('Branch GM');

    // Branch GM needs the approve permission: granted via seeder.
    $approved = (new VoidRefundService)->approve($approval, $gm);
    expect($approved->status)->toBe(VoidRefundApproval::STATUS_APPROVED);
    expect($tx->fresh()->is_voided)->toBeTrue();
    expect($tx->fresh()->void_reason_code_id)->toBe($code->id);

    // Re-approving is a no-op.
    $again = (new VoidRefundService)->approve($approval->fresh(), $gm);
    expect($again->status)->toBe(VoidRefundApproval::STATUS_APPROVED);

    // Unprotected codes execute immediately.
    $tx2 = (new FolioService)->postManualCharge($folio, 'misc', 'Dup', 1000, null, $this->user->id);
    $simple = VoidRefundCode::where('branch_id', $this->branch->id)->where('code', 'ERROR_CORRECTION')->firstOrFail();
    $instant = (new VoidRefundService)->requestVoid($tx2, $simple, $cashier);
    expect($instant->status)->toBe(VoidRefundApproval::STATUS_APPROVED);
    expect($tx2->fresh()->is_voided)->toBeTrue();
});

it('serves the cashier pages under the new permissions', function () {
    $this->withoutVite();

    $this->actingAs($this->user)->get("/branches/{$this->branch->id}/cashier")->assertOk();

    $user = $this->makeAdminUser($this->branch);
    $user->removeRole('Global Admin');

    $this->actingAs($user)->get("/branches/{$this->branch->id}/cashier")->assertForbidden();
});
