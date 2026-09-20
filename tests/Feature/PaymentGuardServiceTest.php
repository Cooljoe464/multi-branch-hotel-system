<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\PosCharge;
use App\Models\Reservation;
use App\Models\User;
use App\Services\FolioService;
use App\Services\PaymentGuardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentGuardServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_mode_returns_pay_first_by_default(): void
    {
        $branch = Branch::factory()->create(['settings' => null]);
        $service = new PaymentGuardService;

        $this->assertSame('pay_first', $service->getMode($branch));
    }

    public function test_get_mode_returns_stored_mode(): void
    {
        $branch = Branch::factory()->create(['settings' => ['payment_guard_mode' => 'pay_after']]);
        $service = new PaymentGuardService;

        $this->assertSame('pay_after', $service->getMode($branch));
    }

    public function test_should_pre_pay_returns_true_when_pay_first(): void
    {
        $branch = Branch::factory()->create(['settings' => ['payment_guard_mode' => 'pay_first']]);
        $service = new PaymentGuardService;

        $this->assertTrue($service->shouldPrePay($branch));
    }

    public function test_should_pre_pay_returns_false_when_pay_after(): void
    {
        $branch = Branch::factory()->create(['settings' => ['payment_guard_mode' => 'pay_after']]);
        $service = new PaymentGuardService;

        $this->assertFalse($service->shouldPrePay($branch));
    }

    public function test_create_charge_creates_pending_pos_charge(): void
    {
        $branch = Branch::factory()->create();
        $reservation = Reservation::factory()->forBranch($branch->id)->create();
        $folio = (new FolioService)->createFolio($branch->id, $reservation->id);
        $service = new PaymentGuardService;

        $charge = $service->createCharge(
            $branch->id,
            $reservation->id,
            $folio->id,
            'restaurant',
            [['name' => 'Burger', 'quantity' => 2, 'unit_price' => 1500, 'total' => 3000]],
            3000,
            300,
            3300
        );

        $this->assertInstanceOf(PosCharge::class, $charge);
        $this->assertDatabaseHas('pos_charges', [
            'branch_id' => $branch->id,
            'reservation_id' => $reservation->id,
            'folio_id' => $folio->id,
            'outlet' => 'restaurant',
            'subtotal' => 3000,
            'tax_amount' => 300,
            'total' => 3300,
            'status' => 'pending',
        ]);
    }

    public function test_dispatch_order_marks_pos_charge_as_posted(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $reservation = Reservation::factory()->forBranch($branch->id)->create();
        $folio = (new FolioService)->createFolio($branch->id, $reservation->id);
        $service = new PaymentGuardService;

        $charge = $service->createCharge(
            $branch->id,
            $reservation->id,
            $folio->id,
            'restaurant',
            [['name' => 'Burger', 'quantity' => 1, 'unit_price' => 1500, 'total' => 1500]],
            1500,
            150,
            1650
        );

        $service->dispatchOrder($charge, $user->id);

        $this->assertDatabaseHas('pos_charges', [
            'id' => $charge->id,
            'status' => 'posted',
        ]);
    }
}
