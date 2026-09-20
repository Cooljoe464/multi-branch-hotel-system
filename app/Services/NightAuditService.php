<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Branding;
use App\Models\DailyLedger;
use App\Models\Folio;
use App\Models\GroupLedger;
use App\Models\Reservation;
use Illuminate\Support\Carbon;

class NightAuditService
{
    private ?Branch $branch = null;

    public function forBranch(Branch $branch): self
    {
        $this->branch = $branch;

        return $this;
    }

    /**
     * @return array{posted: int, errors: list<array{reservation_id: int, error: string}>, total_room_revenue: int, total_tax: int}
     */
    public function postRoomCharges(Carbon $businessDate): array
    {
        $branch = $this->branch;
        if (! $branch) {
            throw new \LogicException('Branch must be set before posting room charges.');
        }

        $reservations = Reservation::forBranch($branch->id)
            ->checkedIn()
            ->where('check_in_date', '<=', $businessDate->toDateString())
            ->where('check_out_date', '>', $businessDate->toDateString())
            ->with(['room', 'roomType', 'folio'])
            ->get();

        $posted = 0;
        $errors = [];
        $totalRoomRevenue = 0;
        $totalTax = 0;

        foreach ($reservations as $reservation) {
            try {
                $folio = Folio::where('reservation_id', $reservation->id)->first();
                if (! $folio) {
                    $folioService = new FolioService;
                    $folio = $folioService->createFolio($branch->id, $reservation->id, null, "Guest Folio: {$reservation->guest_name}");
                }

                $folioService = new FolioService;
                $roomRate = $reservation->room_rate;
                $taxRateBps = (int) ($branch->tax_rate * 100);
                $roomNumber = $reservation->room->number ?? 'N/A';

                $folioService->postDebit(
                    $folio,
                    'room_rate',
                    "Room charge: {$roomNumber} - {$businessDate->format('M d, Y')}",
                    $roomRate,
                    null,
                    $taxRateBps,
                );

                $taxAmount = (int) round($roomRate * $taxRateBps / 10000);
                $totalRoomRevenue += $roomRate;
                $totalTax += $taxAmount;
                $posted++;
            } catch (\Throwable $e) {
                $errors[] = [
                    'reservation_id' => $reservation->id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'posted' => $posted,
            'errors' => $errors,
            'total_room_revenue' => $totalRoomRevenue,
            'total_tax' => $totalTax,
        ];
    }

    public function closeDailyLedger(Carbon $businessDate): DailyLedger
    {
        $branch = $this->branch;
        if (! $branch) {
            throw new \LogicException('Branch must be set before closing daily ledger.');
        }

        $ledger = DailyLedger::forBranch($branch->id)
            ->forDate($businessDate->toDateString())
            ->first();

        if (! $ledger) {
            $ledger = DailyLedger::create([
                'branch_id' => $branch->id,
                'currency_code' => $branch->currency_code,
                'business_date' => $businessDate->toDateString(),
                'status' => 'pending',
            ]);
        }

        if ($ledger->status === 'completed') {
            return $ledger;
        }

        if ($ledger->status === 'in_progress') {
            return $ledger;
        }

        $ledger->markInProgress();

        $roomResult = $this->postRoomCharges($businessDate);

        $totalOtherCharges = (int) $branch->posCharges()
            ->whereDate('created_at', $businessDate)
            ->sum('total');

        $totalPayments = (int) $branch->paymentTransactions()
            ->where('status', 'success')
            ->whereDate('paid_at', $businessDate)
            ->sum('amount');

        $netRevenue = $roomResult['total_room_revenue']
            + $roomResult['total_tax']
            + $totalOtherCharges
            - $totalPayments;

        $ledger->markCompleted([
            'rooms_posted' => $roomResult['posted'],
            'total_room_revenue' => $roomResult['total_room_revenue'],
            'total_tax' => $roomResult['total_tax'],
            'total_other_charges' => $totalOtherCharges,
            'total_payments' => $totalPayments,
            'net_revenue' => $netRevenue,
        ]);

        if (! empty($roomResult['errors'])) {
            $ledger->update(['errors' => $roomResult['errors']]);
        }

        try {
            $auditFlagService = new AuditFlagService;
            $auditFlagService->generate($branch, $businessDate);
        } catch (\Throwable $e) {
            // Audit flag generation is non-critical
        }

        try {
            $currencyCode = $branch->currency_code ?: Branding::instance()->currency_code;

            GroupLedger::updateOrCreate(
                ['branch_id' => $branch->id, 'business_date' => $businessDate->toDateString()],
                [
                    'total_room_revenue' => $roomResult['total_room_revenue'],
                    'total_pos_revenue' => $totalOtherCharges,
                    'total_tax' => $roomResult['total_tax'],
                    'total_payments' => $totalPayments,
                    'net_revenue' => $netRevenue,
                    'currency_code' => $currencyCode,
                    'exchange_rate_to_group' => 1.0,
                ]
            );
        } catch (\Throwable $e) {
            // Group ledger population is non-critical
        }

        return $ledger;
    }

    public function getUnclosedLedger(?Carbon $date = null): ?DailyLedger
    {
        $branch = $this->branch;
        if (! $branch) {
            throw new \LogicException('Branch must be set.');
        }

        $date = $date ?? now()->subDay();

        return DailyLedger::forBranch($branch->id)
            ->forDate($date->toDateString())
            ->first();
    }
}
