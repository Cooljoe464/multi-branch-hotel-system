<?php

namespace App\Imports;

use App\Models\Room;
use App\Models\RoomType;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class RoomImport implements ToModel, WithBatchInserts, WithHeadingRow, WithValidation
{
    private int $branchId;

    private int $created = 0;

    private int $skipped = 0;

    public function __construct(int $branchId)
    {
        $this->branchId = $branchId;
    }

    public function model(array $row): ?Room
    {
        $roomTypeCode = is_string($row['room_type_code'] ?? null) ? (string) $row['room_type_code'] : '';
        $roomType = RoomType::firstOrCreate(
            [
                'branch_id' => $this->branchId,
                'code' => $roomTypeCode,
            ],
            [
                'name' => $row['room_type_name'] ?? $roomTypeCode,
                'base_rate' => (int) ((is_numeric($row['base_rate'] ?? null) ? $row['base_rate'] : 0) * 100),
                'max_occupancy' => $row['max_occupancy'] ?? 2,
                'bed_count' => $row['bed_count'] ?? 1,
                'bed_type' => $row['bed_type'] ?? 'queen',
                'is_active' => true,
            ]
        );

        // Skip if room number already exists for this branch
        $roomNumber = is_string($row['number'] ?? null) ? (string) $row['number'] : '';
        if (Room::where('branch_id', $this->branchId)->where('number', $roomNumber)->exists()) {
            $this->skipped++;

            return null;
        }

        $this->created++;

        return new Room([
            'branch_id' => $this->branchId,
            'room_type_id' => $roomType->id,
            'number' => $roomNumber,
            'floor' => is_string($row['floor'] ?? null) ? (string) $row['floor'] : null,
            'wing' => $row['wing'] ?? null,
            'status' => $row['status'] ?? 'available',
            'is_accessible' => ($row['is_accessible'] ?? 'no') === 'yes',
            'is_smoking' => ($row['is_smoking'] ?? 'no') === 'yes',
            'is_active' => true,
        ]);
    }

    public function rules(): array
    {
        return [
            'number' => 'required|max:10',
            'room_type_code' => 'required|max:10',
            'room_type_name' => 'nullable|string|max:100',
            'floor' => 'nullable|max:10',
            'wing' => 'nullable|string|max:50',
            'status' => 'nullable|string|in:available,occupied,dirty,out_of_order',
            'is_accessible' => 'nullable|string|in:yes,no',
            'is_smoking' => 'nullable|string|in:yes,no',
            'base_rate' => 'nullable|numeric|min:0',
            'max_occupancy' => 'nullable|integer|min:1',
            'bed_count' => 'nullable|integer|min:1',
            'bed_type' => 'nullable|string|in:single,queen,king,twin,sofa',
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
