<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

class StatutoryExport implements Export, WithMultipleSheets
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $annex
     */
    public function __construct(
        private string $kind,
        private array $rows,
        private array $annex,
    ) {}

    /**
     * @return list<FromCollection>
     */
    public function sheets(): array
    {
        return [
            new StatutoryRowsSheet($this->kind, $this->rows),
            new StatutoryAnnexSheet($this->annex),
        ];
    }
}

class StatutoryRowsSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(
        private string $kind,
        private array $rows,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function collection(): Collection
    {
        return collect($this->rows);
    }

    public function title(): string
    {
        return 'Register';
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        $base = ['Confirmation', 'Guest', 'Nationality', 'ID Type', 'ID Number', 'Check-in', 'Check-out', 'Room'];

        return match ($this->kind) {
            'immigration' => [...$base, 'Date of Birth', 'Phone'],
            'police' => [...$base, 'Phone', 'Email', 'Adults'],
            default => [...$base, 'Source', 'Nights', 'Total (minor)'],
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<mixed>
     */
    public function map($row): array
    {
        $base = [
            $row['confirmation'] ?? '',
            $row['guest'] ?? '',
            $row['nationality'] ?? '',
            $row['id_type'] ?? '',
            $row['id_number'] ?? '',
            $row['check_in'] ?? '',
            $row['check_out'] ?? '',
            $row['room'] ?? '',
        ];

        return match ($this->kind) {
            'immigration' => [...$base, $row['dob'] ?? '', $row['phone'] ?? ''],
            'police' => [...$base, $row['phone'] ?? '', $row['email'] ?? '', $row['adults'] ?? ''],
            default => [...$base, $row['source'] ?? '', $row['nights'] ?? '', $row['total'] ?? ''],
        };
    }
}

class StatutoryAnnexSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(
        private array $rows,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function collection(): Collection
    {
        return collect($this->rows);
    }

    public function title(): string
    {
        return 'Exceptions';
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return ['Confirmation', 'Guest', 'Missing'];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<mixed>
     */
    public function map($row): array
    {
        $missing = $row['missing'] ?? [];
        $names = [];
        if (is_array($missing)) {
            foreach ($missing as $field) {
                if (is_string($field)) {
                    $names[] = $field;
                }
            }
        }

        return [$row['confirmation'] ?? '', $row['guest'] ?? '', implode(', ', $names)];
    }
}
