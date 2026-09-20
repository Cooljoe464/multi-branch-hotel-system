<?php

namespace App\Imports;

use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class ReservationImport implements ToModel, WithBatchInserts, WithHeadingRow, WithValidation
{
    private int $branchId;

    private int $created = 0;

    private int $skipped = 0;

    public function __construct(int $branchId)
    {
        $this->branchId = $branchId;
    }

    public function model(array $row): ?Reservation
    {
        // Find or skip by guest email
        $guest = null;
        if (! empty($row['guest_email'])) {
            $guest = Guest::where('email', $row['guest_email'])->first();
        }

        // Find room type
        $roomTypeCode = is_string($row['room_type_code'] ?? null) ? (string) $row['room_type_code'] : '';
        $roomType = RoomType::where('branch_id', $this->branchId)
            ->where('code', $roomTypeCode)
            ->first();

        if (! $roomType) {
            $this->skipped++;

            return null;
        }

        // Find room by number (branch-scoped)
        $room = null;
        if (! empty($row['room_number']) && is_string($row['room_number'])) {
            $room = Room::where('branch_id', $this->branchId)
                ->where('number', $row['room_number'])
                ->first();
        }

        $this->created++;

        return new Reservation([
            'branch_id' => $this->branchId,
            'guest_id' => $guest?->id,
            'room_id' => $room?->id,
            'room_type_id' => $roomType->id,
            'status' => $row['status'] ?? 'confirmed',
            'source' => $row['source'] ?? 'import',
            'guest_name' => $row['guest_name'] ?? ($guest ? $guest->full_name : 'Unknown'),
            'guest_email' => $row['guest_email'] ?? $guest?->email,
            'guest_phone' => is_string($row['guest_phone'] ?? null) ? (string) $row['guest_phone'] : ($guest?->phone),
            'adults' => $row['adults'] ?? 1,
            'children' => $row['children'] ?? 0,
            'check_in_date' => $row['check_in_date'],
            'check_out_date' => $row['check_out_date'],
            'room_rate' => (int) ((is_numeric($row['room_rate'] ?? null) ? $row['room_rate'] : 0) * 100),
            'total_amount' => (int) ((is_numeric($row['total_amount'] ?? null) ? $row['total_amount'] : 0) * 100),
            'amount_paid' => (int) ((is_numeric($row['amount_paid'] ?? null) ? $row['amount_paid'] : 0) * 100),
            'payment_status' => $row['payment_status'] ?? 'pending',
            'special_requests' => ! empty($row['special_requests']) ? [$row['special_requests']] : [],
        ]);
    }

    public function rules(): array
    {
        return [
            'guest_name' => 'nullable|string|max:255',
            'guest_email' => 'nullable|email|max:255',
            'guest_phone' => 'nullable|max:50',
            'room_number' => 'nullable|max:10',
            'room_type_code' => 'required|max:10',
            'status' => 'nullable|string|in:pending,confirmed,reserved,checked_in,checked_out,cancelled',
            'source' => 'nullable|string|max:50',
            'check_in_date' => 'required|date',
            'check_out_date' => 'required|date|after:check_in_date',
            'room_rate' => 'nullable|numeric|min:0',
            'total_amount' => 'nullable|numeric|min:0',
            'amount_paid' => 'nullable|numeric|min:0',
            'adults' => 'nullable|integer|min:1',
            'children' => 'nullable|integer|min:0',
            'payment_status' => 'nullable|string|in:pending,partial,paid,refunded',
            'special_requests' => 'nullable|string|max:500',
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
