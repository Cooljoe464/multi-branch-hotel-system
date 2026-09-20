<?php

namespace App\Http\Controllers;

use App\Exports\FinancialSummaryExport;
use App\Exports\NightAuditExport;
use App\Models\DailyLedger;
use App\Models\KitchenWasteLog;
use App\Models\PosCharge;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $branchId = (int) $user->branch_id;

        $ledgers = DailyLedger::forBranch($branchId)
            ->completed()
            ->orderBy('business_date', 'desc')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('analytics/Reports', [
            'ledgers' => $ledgers,
            'branch' => $user->currentBranch,
        ]);
    }

    public function nightAuditExport(Request $request): BinaryFileResponse
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $branchId = (int) $user->branch_id;
        $startDate = $request->string('start_date')->value();
        $endDate = $request->string('end_date')->value();

        $filename = 'night-audit-'.$startDate.'-to-'.$endDate.'.csv';

        return Excel::download(
            new NightAuditExport($branchId, $startDate, $endDate),
            $filename
        );
    }

    public function financialExport(Request $request): BinaryFileResponse
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $branchId = (int) $user->branch_id;
        $startDate = $request->string('start_date')->value();
        $endDate = $request->string('end_date')->value();

        $filename = 'financial-summary-'.$startDate.'-to-'.$endDate.'.csv';

        return Excel::download(
            new FinancialSummaryExport($branchId, $startDate, $endDate),
            $filename
        );
    }

    public function profitAndLoss(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $branchId = (int) $user->branch_id;

        $ledgers = DailyLedger::forBranch($branchId)->completed()->get();

        $totalRoomRevenue = 0;
        $totalOtherCharges = 0;
        $totalTax = 0;
        $totalPayments = 0;

        foreach ($ledgers as $ledger) {
            $totalRoomRevenue += $ledger->total_room_revenue;
            $totalOtherCharges += $ledger->total_other_charges;
            $totalTax += $ledger->total_tax;
            $totalPayments += $ledger->total_payments;
        }

        $wasteCost = (int) KitchenWasteLog::where('branch_id', $branchId)->sum('cost');

        $posRevenue = (int) PosCharge::where('branch_id', $branchId)->posted()->sum('total');

        $netRevenue = $totalRoomRevenue + $totalOtherCharges + $posRevenue;
        $totalExpenses = $wasteCost;

        $netIncome = $netRevenue - $totalExpenses;

        return Inertia::render('analytics/ProfitAndLoss', [
            'summary' => [
                'room_revenue' => $totalRoomRevenue,
                'pos_revenue' => $posRevenue,
                'other_charges' => $totalOtherCharges,
                'total_tax' => $totalTax,
                'total_payments' => $totalPayments,
                'waste_cost' => $wasteCost,
                'net_revenue' => $netRevenue,
                'total_expenses' => $totalExpenses,
                'net_income' => $netIncome,
            ],
        ]);
    }

    public function channelYield(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $branchId = (int) $user->branch_id;

        $channelStats = DailyLedger::forBranch($branchId)
            ->completed()
            ->select('business_date', 'total_room_revenue', 'rooms_posted')
            ->orderBy('business_date', 'desc')
            ->limit(30)
            ->get();

        return Inertia::render('analytics/ChannelYield', [
            'channelYieldData' => $channelStats,
        ]);
    }

    public function taxLiability(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $branchId = (int) $user->branch_id;

        $taxSummary = DailyLedger::forBranch($branchId)
            ->completed()
            ->select('business_date', 'total_tax')
            ->orderBy('business_date', 'desc')
            ->limit(30)
            ->get();

        return Inertia::render('analytics/TaxLiability', [
            'taxSummary' => $taxSummary,
        ]);
    }
}
