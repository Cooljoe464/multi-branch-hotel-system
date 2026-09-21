<?php

namespace App\Services;

use App\Events\ShiftClosed;
use App\Events\VarianceFlagged;
use App\Models\Branch;
use App\Models\CashierShift;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Cashier drawer lifecycle. Expected cash is exact: opening float plus
 * cash payments stamped to the shift at posting time (see FolioService).
 * Cash means method cash (description prefix); card/online never enter
 * the drawer even when the same cashier posts them.
 */
class CashierShiftService
{
    public function open(Branch $branch, User $cashier, int $floatMinor, string $businessDate): CashierShift
    {
        if ($floatMinor < 0) {
            throw new LogicException('Opening float cannot be negative.');
        }

        return DB::transaction(function () use ($branch, $cashier, $floatMinor, $businessDate) {
            $existing = CashierShift::where('user_id', $cashier->id)
                ->open()
                ->lockForUpdate()
                ->first();

            if ($existing) {
                throw new LogicException('Cashier already has an open shift.');
            }

            return CashierShift::create([
                'branch_id' => $branch->id,
                'user_id' => $cashier->id,
                'business_date' => $businessDate,
                'opening_float_minor' => $floatMinor,
                'status' => CashierShift::STATUS_OPEN,
                'opened_at' => now(),
            ]);
        });
    }

    /**
     * The cashier's currently open shift, if any.
     */
    public function currentFor(User $cashier): ?CashierShift
    {
        return CashierShift::where('user_id', $cashier->id)->open()->first();
    }

    public function close(CashierShift $shift, int $countedMinor, ?string $note = null): CashierShift
    {
        return DB::transaction(function () use ($shift, $countedMinor, $note) {
            $locked = CashierShift::where('id', $shift->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw new LogicException('Shift is already closed.');
            }

            if ($countedMinor < 0) {
                throw new LogicException('Counted cash cannot be negative.');
            }

            $expected = $locked->opening_float_minor + $this->cashPayments($locked);
            $variance = $countedMinor - $expected;

            if (abs($variance) > $this->varianceThreshold($locked) && ($note === null || trim($note) === '')) {
                throw new LogicException('A note is required for variances above threshold.');
            }

            $locked->update([
                'expected_cash_minor' => $expected,
                'counted_cash_minor' => $countedMinor,
                'variance_minor' => $variance,
                'status' => CashierShift::STATUS_CLOSED,
                'closed_at' => now(),
            ]);

            $fresh = $locked->fresh() ?? $locked;
            event(new ShiftClosed($fresh));

            if ($variance !== 0) {
                event(new VarianceFlagged($fresh));
            }

            return $fresh;
        });
    }

    public function reconcile(CashierShift $shift): CashierShift
    {
        $shift->update(['status' => CashierShift::STATUS_RECONCILED]);

        return $shift->fresh() ?? $shift;
    }

    /**
     * Z-report payload: category totals plus drawer arithmetic.
     *
     * @return array<string, mixed>
     */
    public function zReport(CashierShift $shift): array
    {
        $lines = Transaction::where('cashier_shift_id', $shift->id)
            ->where('is_voided', false)
            ->selectRaw('type, category, sum(amount) as total, count(*) as lines')
            ->groupBy('type', 'category')
            ->get();

        return [
            'shift' => $shift->fresh() ?? $shift,
            'opening_float_minor' => $shift->opening_float_minor,
            'expected_cash_minor' => $shift->expected_cash_minor,
            'counted_cash_minor' => $shift->counted_cash_minor,
            'variance_minor' => $shift->variance_minor,
            'lines' => $lines,
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    private function cashPayments(CashierShift $shift): int
    {
        return (int) Transaction::where('cashier_shift_id', $shift->id)
            ->where('type', 'credit')
            ->where('category', 'payment')
            ->where('is_voided', false)
            ->where('description', 'like', 'Cash payment%')
            ->sum('amount');
    }

    private function varianceThreshold(CashierShift $shift): int
    {
        $settings = $shift->branch->settings;
        $threshold = $settings['cash_variance_threshold_minor'] ?? 1000;

        return is_int($threshold) ? $threshold : 1000;
    }
}
