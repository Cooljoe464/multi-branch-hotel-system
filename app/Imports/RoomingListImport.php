<?php

namespace App\Imports;

use App\Exceptions\AvailabilityException;
use App\Models\GroupBlock;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\GroupBlockService;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

/**
 * Rooming-list import: one row per guest, booked as block pickup.
 * Re-imports are idempotent on the natural key
 * (block + email + check-in); a trace key block.{id}.row.{n} is frozen
 * into metadata for audit.
 */
class RoomingListImport implements ToModel, WithBatchInserts, WithHeadingRow, WithValidation
{
    private int $branchId;

    private GroupBlock $block;

    private int $rowNumber = 0;

    private int $created = 0;

    private int $skipped = 0;

    public function __construct(int $branchId, int $blockId)
    {
        $block = GroupBlock::where('id', $blockId)->where('branch_id', $branchId)->first();

        if (! $block) {
            throw new InvalidArgumentException("Group block {$blockId} does not belong to branch {$branchId}.");
        }

        $this->branchId = $branchId;
        $this->block = $block;
    }

    public function model(array $row): ?Reservation
    {
        $this->rowNumber++;
        $key = "block.{$this->block->id}.row.{$this->rowNumber}";

        $roomTypeCode = is_string($row['room_type_code'] ?? null) ? (string) $row['room_type_code'] : '';
        $roomType = RoomType::where('branch_id', $this->branchId)
            ->where('code', $roomTypeCode)
            ->first();

        $email = is_string($row['guest_email'] ?? null) ? trim((string) $row['guest_email']) : '';
        $checkIn = is_string($row['check_in_date'] ?? null) ? (string) $row['check_in_date'] : '';
        $checkOut = is_string($row['check_out_date'] ?? null) ? (string) $row['check_out_date'] : '';

        if (! $roomType || $email === '' || $checkIn === '' || $checkOut === '') {
            $this->skipped++;

            return null;
        }

        $duplicate = Reservation::where('group_block_id', $this->block->id)
            ->where('guest_email', $email)
            ->whereDate('check_in_date', $checkIn)
            ->exists();

        if ($duplicate) {
            $this->skipped++;

            return null;
        }

        $guest = Guest::firstOrCreate(
            ['email' => $email],
            [
                'first_name' => is_string($row['guest_name'] ?? null) ? (string) $row['guest_name'] : 'Group Guest',
                'last_name' => '',
            ],
        );

        $nights = max(1, (int) Carbon::parse($checkIn)->diffInDays(Carbon::parse($checkOut)));
        $rate = $roomType->base_rate;

        try {
            // Pickup persists the reservation itself (real inventory);
            // returning null keeps Excel from inserting it a second time.
            (new GroupBlockService)->pickup(
                $this->block->fresh() ?? $this->block,
                $roomType,
                $checkIn,
                $checkOut,
                [
                    'currency_code' => $this->block->branch->currency_code,
                    'guest_id' => $guest->id,
                    'guest_name' => is_string($row['guest_name'] ?? null) && $row['guest_name'] !== '' ? (string) $row['guest_name'] : $guest->full_name,
                    'guest_email' => $email,
                    'adults' => is_numeric($row['adults'] ?? null) ? (int) $row['adults'] : 1,
                    'children' => 0,
                    'room_rate' => $rate,
                    'total_amount' => $rate * $nights,
                    'status' => 'confirmed',
                    'source' => 'rooming_list',
                    'payment_status' => 'pending',
                    'metadata' => ['rooming_key' => $key],
                ],
            );
        } catch (AvailabilityException) {
            $this->skipped++;

            return null;
        }

        $this->created++;

        return null;
    }

    public function rules(): array
    {
        return [
            'guest_name' => 'nullable|string|max:255',
            'guest_email' => 'nullable|email|max:255',
            'room_type_code' => 'required|max:10',
            'check_in_date' => 'required|date',
            'check_out_date' => 'required|date|after:check_in_date',
            'adults' => 'nullable|integer|min:1',
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
