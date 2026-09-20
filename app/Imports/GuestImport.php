<?php

namespace App\Imports;

use App\Models\Branding;
use App\Models\Guest;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class GuestImport implements ToModel, WithBatchInserts, WithHeadingRow, WithValidation
{
    private int $created = 0;

    private int $skipped = 0;

    public function model(array $row): ?Guest
    {
        // Skip if email already exists (global unique)
        if (! empty($row['email']) && Guest::where('email', $row['email'])->exists()) {
            $this->skipped++;

            return null;
        }

        $this->created++;

        return new Guest([
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'email' => $row['email'] ?? null,
            'phone' => isset($row['phone']) && is_string($row['phone']) ? $row['phone'] : null,
            'date_of_birth' => $row['date_of_birth'] ?? null,
            'nationality' => $row['nationality'] ?? null,
            'id_type' => $row['id_type'] ?? null,
            'id_number' => $row['id_number'] ?? null,
            'company' => $row['company'] ?? null,
            'job_title' => $row['job_title'] ?? null,
            'vip_status' => $row['vip_status'] ?? 'none',
            'total_stays' => $row['total_stays'] ?? 0,
            'total_nights' => $row['total_nights'] ?? 0,
            'total_spent' => (int) ((is_numeric($row['total_spent'] ?? null) ? $row['total_spent'] : 0) * 100),
            'preferred_language' => $row['preferred_language'] ?? 'en',
            'preferred_currency' => $row['preferred_currency'] ?? Branding::instance()->currency_code,
            'special_notes' => $row['special_notes'] ?? null,
            'internal_notes' => $row['internal_notes'] ?? null,
        ]);
    }

    public function rules(): array
    {
        return [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|max:50',
            'date_of_birth' => 'nullable|date',
            'nationality' => 'nullable|string|max:2',
            'id_type' => 'nullable|string|in:passport,drivers_license,national_id',
            'id_number' => 'nullable|string|max:50',
            'company' => 'nullable|string|max:255',
            'job_title' => 'nullable|string|max:255',
            'vip_status' => 'nullable|string|in:none,silver,gold,platinum,diamond',
            'total_stays' => 'nullable|integer|min:0',
            'total_nights' => 'nullable|integer|min:0',
            'total_spent' => 'nullable|numeric|min:0',
            'preferred_language' => 'nullable|string|max:5',
            'preferred_currency' => 'nullable|string|max:3',
            'special_notes' => 'nullable|string|max:1000',
            'internal_notes' => 'nullable|string|max:1000',
        ];
    }

    public function batchSize(): int
    {
        return 100;
    }

    public function getCreatedCount(): int
    {
        return $this->created;
    }

    public function getSkippedCount(): int
    {
        return $this->skipped;
    }
}
