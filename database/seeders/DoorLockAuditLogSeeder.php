<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\DoorLockAuditLog;
use App\Models\DoorLockGateway;
use App\Models\Reservation;
use App\Models\Room;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class DoorLockAuditLogSeeder extends Seeder
{
    public function run(): void
    {
        $branches = Branch::all();

        foreach ($branches as $branch) {
            $gateway = DoorLockGateway::where('branch_id', $branch->id)->first();
            $reservations = Reservation::where('branch_id', $branch->id)
                ->whereIn('status', ['checked_in', 'checked_out'])
                ->get();
            $rooms = Room::where('branch_id', $branch->id)->get();

            if (! $gateway || $reservations->isEmpty() || $rooms->isEmpty()) {
                continue;
            }

            $this->createAuditLogs($branch, $gateway, $reservations, $rooms);
        }
    }

    /**
     * @param  Collection<int, Reservation>  $reservations
     * @param  Collection<int, Room>  $rooms
     */
    private function createAuditLogs(Branch $branch, DoorLockGateway $gateway, Collection $reservations, Collection $rooms): void
    {
        $actions = ['unlock', 'lock', 'encode_credential', 'revoke_credential'];
        $statuses = ['success', 'success', 'success', 'failed'];

        for ($i = 0; $i < 10; $i++) {
            $reservation = $reservations[$i % $reservations->count()] ?? null;
            $room = $rooms[$i % $rooms->count()] ?? null;

            if (! $reservation || ! $room) {
                continue;
            }

            $action = $actions[$i % count($actions)];
            $status = $statuses[$i % count($statuses)];

            $validFrom = now()->subDays(rand(0, 7));
            $validUntil = (clone $validFrom)->addDays(rand(1, 7));

            DoorLockAuditLog::firstOrCreate(
                [
                    'branch_id' => $branch->id,
                    'reservation_id' => $reservation->id,
                    'room_id' => $room->id,
                    'gateway_id' => $gateway->id,
                    'action' => $action,
                ],
                [
                    'credential_id' => strtoupper(bin2hex(random_bytes(6))),
                    'pin_code' => str_pad((string) rand(1000, 9999), 4, '0', STR_PAD_LEFT),
                    'valid_from' => $validFrom,
                    'valid_until' => $validUntil,
                    'status' => $status,
                    'payload' => [
                        'device_id' => strtoupper(bin2hex(random_bytes(8))),
                        'firmware_version' => 2.5,
                        'battery_level' => rand(70, 100),
                    ],
                ]
            );
        }
    }
}
