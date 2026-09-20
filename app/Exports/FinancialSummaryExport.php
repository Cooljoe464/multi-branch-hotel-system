<?php

namespace App\Exports;

use App\Models\DailyLedger;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class FinancialSummaryExport implements FromCollection, WithHeadings, WithMapping, WithStyles
{
    private int $branchId;

    private string $startDate;

    private string $endDate;

    private int $row = 0;

    public function __construct(int $branchId, string $startDate, string $endDate)
    {
        $this->branchId = $branchId;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
    }

    /** @return Collection<int, array{date: string, rooms_posted: int|float, room_revenue: float|int, tax: float|int, other_charges: float|int, payments: float|int, net_revenue: float|int, adr: float|int}> */
    public function collection(): Collection
    {
        $ledgers = DailyLedger::forBranch($this->branchId)
            ->where('business_date', '>=', $this->startDate)
            ->where('business_date', '<=', $this->endDate)
            ->orderBy('business_date')
            ->get();

        $daysCount = $ledgers->count();

        /** @var int $totalRoomsPosted */
        $totalRoomsPosted = $ledgers->reduce(fn (int $carry, DailyLedger $l) => $carry + $l->rooms_posted, 0);
        /** @var int $totalRoomRevenue */
        $totalRoomRevenue = $ledgers->reduce(fn (int $carry, DailyLedger $l) => $carry + $l->total_room_revenue, 0);
        /** @var int $totalTax */
        $totalTax = $ledgers->reduce(fn (int $carry, DailyLedger $l) => $carry + $l->total_tax, 0);
        /** @var int $totalOtherCharges */
        $totalOtherCharges = $ledgers->reduce(fn (int $carry, DailyLedger $l) => $carry + $l->total_other_charges, 0);
        /** @var int $totalPayments */
        $totalPayments = $ledgers->reduce(fn (int $carry, DailyLedger $l) => $carry + $l->total_payments, 0);
        /** @var int $netRevenue */
        $netRevenue = $ledgers->reduce(fn (int $carry, DailyLedger $l) => $carry + $l->net_revenue, 0);

        $totals = [
            'rooms_posted' => $totalRoomsPosted,
            'total_room_revenue' => $totalRoomRevenue,
            'total_tax' => $totalTax,
            'total_other_charges' => $totalOtherCharges,
            'total_payments' => $totalPayments,
            'net_revenue' => $netRevenue,
        ];

        /** @var array<int, array{date: string, rooms_posted: int|float, room_revenue: float, tax: float, other_charges: float, payments: float, net_revenue: float, adr: float|int}> $rows */
        $rows = $ledgers->map(fn (DailyLedger $l) => [
            'date' => $l->business_date->format('Y-m-d'),
            'rooms_posted' => $l->rooms_posted ?? 0,
            'room_revenue' => ($l->total_room_revenue ?? 0) / 100,
            'tax' => ($l->total_tax ?? 0) / 100,
            'other_charges' => ($l->total_other_charges ?? 0) / 100,
            'payments' => ($l->total_payments ?? 0) / 100,
            'net_revenue' => ($l->net_revenue ?? 0) / 100,
            'adr' => $l->rooms_posted > 0 ? round($l->total_room_revenue / $l->rooms_posted / 100, 2) : 0,
        ])->toArray();

        $rows[] = [
            'date' => 'TOTALS',
            'rooms_posted' => $totals['rooms_posted'],
            'room_revenue' => $totals['total_room_revenue'] / 100,
            'tax' => $totals['total_tax'] / 100,
            'other_charges' => $totals['total_other_charges'] / 100,
            'payments' => $totals['total_payments'] / 100,
            'net_revenue' => $totals['net_revenue'] / 100,
            'adr' => $totals['rooms_posted'] > 0 ? round($totals['total_room_revenue'] / $totals['rooms_posted'] / 100, 2) : 0,
        ];

        $rows[] = [
            'date' => 'AVERAGES',
            'rooms_posted' => $daysCount > 0 ? round($totals['rooms_posted'] / $daysCount, 1) : 0,
            'room_revenue' => $daysCount > 0 ? round($totals['total_room_revenue'] / $daysCount / 100, 2) : 0,
            'tax' => $daysCount > 0 ? round($totals['total_tax'] / $daysCount / 100, 2) : 0,
            'other_charges' => $daysCount > 0 ? round($totals['total_other_charges'] / $daysCount / 100, 2) : 0,
            'payments' => $daysCount > 0 ? round($totals['total_payments'] / $daysCount / 100, 2) : 0,
            'net_revenue' => $daysCount > 0 ? round($totals['net_revenue'] / $daysCount / 100, 2) : 0,
            'adr' => 0,
        ];

        return collect($rows);
    }

    public function headings(): array
    {
        return [
            'Date',
            'Rooms Posted',
            'Room Revenue',
            'Tax',
            'Other Charges',
            'Payments',
            'Net Revenue',
            'ADR',
        ];
    }

    /** @param array{date: string, rooms_posted: int|float, room_revenue: float, tax: float, other_charges: float, payments: float, net_revenue: float, adr: float|int} $row */
    public function map($row): array
    {
        $this->row++;

        return [
            $row['date'],
            $row['rooms_posted'],
            $row['room_revenue'],
            $row['tax'],
            $row['other_charges'],
            $row['payments'],
            $row['net_revenue'],
            $row['adr'],
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $lastRow = $this->row + 1;

        return [
            1 => ['font' => ['bold' => true]],
            $lastRow - 1 => ['font' => ['bold' => true]],
            $lastRow => ['font' => ['bold' => true, 'italic' => true]],
        ];
    }
}
