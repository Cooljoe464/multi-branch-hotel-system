<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\PosCharge;

class PaymentGuardService
{
    public function getMode(Branch $branch): string
    {
        $settings = $branch->settings ?? [];
        $mode = $settings['payment_guard_mode'] ?? 'pay_first';

        return is_string($mode) ? $mode : 'pay_first';
    }

    public function shouldPrePay(Branch $branch): bool
    {
        return $this->getMode($branch) === 'pay_first';
    }

    public function dispatchOrder(PosCharge $posCharge, ?int $userId = null): void
    {
        $this->getMode($posCharge->branch);

        $posCharge->update([
            'status' => 'posted',
            'posted_at' => now(),
        ]);

        $resolvedUserId = $userId ?? (int) auth()->id();

        $folioService = new FolioService;
        $folioService->postPosCharge($posCharge, $resolvedUserId);

        $posCharge->kotItems()->update(['status' => 'pending']);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function createCharge(
        int $branchId,
        int $reservationId,
        int $folioId,
        string $outlet,
        array $items,
        int $subtotal,
        int $taxAmount,
        int $total
    ): PosCharge {
        return PosCharge::create([
            'branch_id' => $branchId,
            'currency_code' => Branch::find($branchId)?->currency_code ?? 'NGN',
            'reservation_id' => $reservationId,
            'folio_id' => $folioId,
            'outlet' => $outlet,
            'items' => $items,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'status' => 'pending',
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function createAndDispatchCharge(
        int $branchId,
        int $reservationId,
        int $folioId,
        string $outlet,
        array $items,
        int $subtotal,
        int $taxAmount,
        int $total
    ): PosCharge {
        $posCharge = $this->createCharge(
            $branchId,
            $reservationId,
            $folioId,
            $outlet,
            $items,
            $subtotal,
            $taxAmount,
            $total
        );

        if (! $this->shouldPrePay($posCharge->branch)) {
            $this->dispatchOrder($posCharge);
        }

        return $posCharge;
    }
}
