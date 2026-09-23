<?php

namespace App\Services;

use App\Events\GuestMoved;
use App\Events\KeyReissued;
use App\Exceptions\AvailabilityException;
use App\Models\Reservation;
use App\Models\ReservationMove;
use App\Models\Room;
use App\Models\Task;
use App\Models\User;
use App\Services\DoorLock\DoorLockService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Mid-stay room moves. One transaction: lock reservation + both rooms,
 * repoint future nights, flip room states, reissue the door key, and
 * queue housekeeping for both rooms. Folio charges are untouched.
 * Key-gateway failure rolls everything back (never half-moved).
 */
class RoomMoveService
{
    /**
     * @param  array<string, mixed>  $options  reason.
     */
    public function move(
        Reservation $reservation,
        Room $toRoom,
        ?User $movedBy = null,
        ?string $idempotencyKey = null,
        array $options = [],
    ): ReservationMove {
        $today = Carbon::today()->toDateString();

        return DB::transaction(function () use ($reservation, $toRoom, $movedBy, $idempotencyKey, $options, $today) {
            if ($idempotencyKey !== null) {
                $replay = ReservationMove::where('reservation_id', $reservation->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($replay) {
                    return $replay;
                }
            }

            $locked = Reservation::where('id', $reservation->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, ['reserved', 'confirmed', 'checked_in'], true)) {
                throw new AvailabilityException('MOVE_STATE', 'Only active reservations can be moved.');
            }

            if ($toRoom->branch_id !== $locked->branch_id) {
                throw new AvailabilityException('ROOM_NOT_IN_BRANCH', 'The target room does not belong to this property.');
            }

            $fromRoom = $locked->room_id !== null ? Room::where('id', $locked->room_id)->lockForUpdate()->first() : null;
            $target = Room::where('id', $toRoom->id)->lockForUpdate()->firstOrFail();

            if ($fromRoom && $target->id === $fromRoom->id) {
                throw new AvailabilityException('MOVE_SAME_ROOM', 'The guest is already in that room.');
            }

            if ($target->status !== 'available') {
                throw new AvailabilityException('ROOM_CONFLICT', "Room {$target->number} is not available.");
            }

            $reason = $options['reason'] ?? null;
            $reason = is_string($reason) ? $reason : null;

            // Repoint future nights (conflict-checked inside); past
            // nights keep the old room for an accurate history.
            (new AvailabilityService)->setRoomForRemainingNights($locked, $target, $today);

            $locked->update(['room_id' => $target->id]);
            $target->update(['status' => 'occupied']);

            if ($fromRoom) {
                // A room that never hosted the guest goes back to
                // available; a vacated occupied room needs turnover.
                $fromRoom->update([
                    'status' => $fromRoom->status === 'occupied' ? 'dirty' : 'available',
                    'condition' => 'dirty',
                    'condition_reason' => "Move of {$locked->confirmation_number} to {$target->number}.",
                ]);

                Task::create([
                    'branch_id' => $locked->branch_id,
                    'room_id' => $fromRoom->id,
                    'type' => 'turnover',
                    'priority' => 'normal',
                    'status' => 'open',
                    'description' => "Turnover after move of {$locked->confirmation_number} to {$target->number}.",
                ]);
            }

            Task::create([
                'branch_id' => $locked->branch_id,
                'room_id' => $target->id,
                'type' => 'inspection',
                'priority' => 'normal',
                'status' => 'open',
                'description' => "Inspect {$target->number} after move-in of {$locked->confirmation_number}.",
            ]);

            $move = ReservationMove::create([
                'branch_id' => $locked->branch_id,
                'reservation_id' => $locked->id,
                'from_room_id' => $fromRoom?->id,
                'to_room_id' => $target->id,
                'moved_at' => now(),
                'moved_by' => $movedBy?->id,
                'reason' => $reason,
                'key_reissued' => false,
                'idempotency_key' => $idempotencyKey,
            ]);

            // Key failure throws below and rolls the whole move back.
            $log = (new DoorLockService)->forBranch($locked->branch)->issueKey($locked->fresh() ?? $locked);

            if ($log->status !== 'success') {
                throw new AvailabilityException('KEY_REISSUE_FAILED', "Door key reissue failed for room {$target->number}.");
            }

            $move->update(['key_reissued' => true]);

            event(new GuestMoved($locked->fresh() ?? $locked, $move->fresh() ?? $move));
            event(new KeyReissued($locked->fresh() ?? $locked, $target));

            return $move->fresh() ?? $move;
        }, 3);
    }
}
