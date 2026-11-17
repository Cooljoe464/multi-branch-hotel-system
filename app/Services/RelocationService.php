<?php

namespace App\Services;

use App\Exceptions\AvailabilityException;
use App\Models\Branch;
use App\Models\Relocation;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RelocationService
{
    public function create(
        Reservation $reservation,
        Branch $destinationBranch,
        Room $destinationRoom,
        int $compensationMinor,
        string $reason,
        string $idempotencyKey,
        ?User $createdBy = null,
    ): Relocation {
        return DB::transaction(function () use ($reservation, $destinationBranch, $destinationRoom, $compensationMinor, $reason, $idempotencyKey, $createdBy): Relocation {
            $reservation = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            $existing = Relocation::query()
                ->where('reservation_id', $reservation->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            if (! $reservation->overbooked) {
                throw new AvailabilityException('RELOCATION_NOT_REQUIRED', 'Relocation is only available for an overbooked reservation.');
            }

            $destinationBranch = Branch::query()->whereKey($destinationBranch->id)->lockForUpdate()->firstOrFail();
            $destinationRoom = Room::query()->whereKey($destinationRoom->id)->lockForUpdate()->firstOrFail();

            if ($reservation->branch->tenant_id !== $destinationBranch->tenant_id) {
                throw new AvailabilityException('RELOCATION_TENANT_MISMATCH', 'The destination property must belong to the same tenant.');
            }

            $destinationType = $destinationRoom->roomType;
            $occupants = $reservation->adults + $reservation->children;

            if ($destinationRoom->branch_id !== $destinationBranch->id
                || $destinationType->branch_id !== $destinationBranch->id
                || ! $destinationType->is_active
                || $destinationType->max_occupancy < $occupants
                || $destinationRoom->status !== 'available') {
                throw new AvailabilityException('RELOCATION_ROOM_UNAVAILABLE', 'The destination room is unavailable or incompatible.');
            }

            if ($compensationMinor <= 0) {
                throw new AvailabilityException('RELOCATION_COMPENSATION_REQUIRED', 'A positive guest compensation amount is required.');
            }

            $relocation = Relocation::create([
                'tenant_id' => $reservation->branch->tenant_id,
                'reservation_id' => $reservation->id,
                'source_branch_id' => $reservation->branch_id,
                'destination_branch_id' => $destinationBranch->id,
                'destination_room_id' => $destinationRoom->id,
                'status' => 'pending',
                'reason' => $reason,
                'compensation_minor' => $compensationMinor,
                'currency_code' => $reservation->currency_code ?? $reservation->branch->currency_code,
                'idempotency_key' => $idempotencyKey,
                'created_by' => $createdBy?->id,
            ]);

            $folio = $reservation->folio ?? (new FolioService)->createFolio($reservation->branch_id, $reservation->id, description: 'Relocation compensation');
            $transaction = (new FolioService)->postCredit(
                $folio,
                'relocation_compensation',
                'Relocation compensation: '.$reason,
                $compensationMinor,
                $createdBy?->id,
                referenceType: Relocation::class,
                referenceId: $relocation->id,
                journalEvent: 'relocation.compensation',
            );
            $relocation->update(['compensation_transaction_id' => $transaction->id]);

            $reservation->update(['metadata' => array_merge($reservation->metadata ?? [], [
                'relocation_required' => true,
                'relocation_id' => $relocation->id,
                'relocation_status' => $relocation->status,
            ])]);

            return $relocation->fresh() ?? $relocation;
        }, 3);
    }
}
