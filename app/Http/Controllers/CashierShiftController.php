<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\EnsuresBranchAccess;
use App\Models\Branch;
use App\Models\CashierShift;
use App\Services\BusinessDateService;
use App\Services\CashierShiftService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CashierShiftController extends Controller
{
    use EnsuresBranchAccess;

    public function __construct(private CashierShiftService $shifts, private BusinessDateService $businessDates) {}

    public function index(Request $request, Branch $branch): Response
    {
        $this->ensureBranchAccess($branch);

        $shifts = CashierShift::forBranch($branch->id)
            ->with('cashier')
            ->orderByDesc('opened_at')
            ->limit(30)
            ->get();

        $user = $request->user();
        abort_unless($user !== null, 401);

        $current = null;
        foreach ($shifts as $shift) {
            if ($shift->isOpen() && (int) $shift->user_id === $user->id) {
                $current = $shift;

                break;
            }
        }

        return Inertia::render('cashier/Shift', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'shifts' => $shifts,
            'current' => $current,
        ]);
    }

    public function open(Request $request, Branch $branch): RedirectResponse
    {
        $this->ensureBranchAccess($branch);

        $request->validate(['opening_float_minor' => 'required|integer|min:0']);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $this->shifts->open(
            $branch,
            $user,
            $request->integer('opening_float_minor'),
            $this->businessDates->current($branch)->business_date->toDateString(),
        );

        return $this->flashSuccess('Shift opened.');
    }

    public function close(Request $request, CashierShift $shift): RedirectResponse
    {
        $this->ensureBranchAccess($shift->branch);

        $request->validate([
            'counted_cash_minor' => 'required|integer|min:0',
            'note' => 'nullable|string|max:500',
        ]);

        $closed = $this->shifts->close(
            $shift,
            $request->integer('counted_cash_minor'),
            $request->string('note')->value() !== '' ? $request->string('note')->value() : null,
        );

        return $this->flashSuccess(
            $closed->variance_minor === 0
                ? 'Shift closed. Drawer balances.'
                : "Shift closed with variance {$closed->variance_minor} minor units."
        );
    }

    public function zReport(Branch $branch, CashierShift $shift): Response
    {
        $this->ensureBranchAccess($branch);
        abort_unless($shift->branch_id === $branch->id, 404);

        return Inertia::render('cashier/ZReport', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'report' => $this->shifts->zReport($shift),
        ]);
    }
}
