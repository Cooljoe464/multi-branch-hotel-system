<?php

namespace App\Exports;

use App\Models\DailyLedger;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class NightAuditExport implements FromCollection, WithHeadings, WithMapping, WithStyles
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

    /** @return Collection<int, DailyLedger> */
    public function collection(): Collection
    {
        return DailyLedger::forBranch($this->branchId)
            ->where('business_date', '>=', $this->startDate)
            ->where('business_date', '<=', $this->endDate)
            ->orderBy('business_date')
            ->get();
    }

    public function headings(): array
    {
        return [
            'Date',
            'Status',
            'Rooms Posted',
            'Room Revenue',
            'Tax',
            'Other Charges',
            'Payments',
            'Net Revenue',
        ];
    }

    /** @param DailyLedger $ledger */
    public function map($ledger): array
    {
        $this->row++;

        return [
            $ledger->business_date->format('Y-m-d'),
            ucfirst($ledger->status),
            $ledger->rooms_posted ?? 0,
            ($ledger->total_room_revenue ?? 0) / 100,
            ($ledger->total_tax ?? 0) / 100,
            ($ledger->total_other_charges ?? 0) / 100,
            ($ledger->total_payments ?? 0) / 100,
            ($ledger->net_revenue ?? 0) / 100,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
